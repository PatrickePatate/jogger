<?php

declare(strict_types=1);

namespace Tests\Feature\Logs;

use App\Domain\Access\Actions\CreateApiToken;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Logs\LogIngestor;
use App\Domain\Jobs\Logs\SecretSource;
use App\Domain\Jobs\Models\JobLog;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetrievalTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private WorkflowJob $job;

    private Runner $runner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('logs');
        config(['jogger.log_disk' => 'logs']);
        $this->host = Host::factory()->create();
        $this->runner = Runner::factory()->create(['host_id' => $this->host->id]);
        $this->job = WorkflowJob::factory()->create(['runner_id' => $this->runner->id]);
        $this->admin = User::factory()->admin()->create();
    }

    private function ship(int $seq, string $data, bool $eof = false): void
    {
        app(LogIngestor::class)->ingest($this->host, $this->runner->id, $seq, $data, $eof);
    }

    public function test_scrollback_for_a_running_log_reports_where_the_stream_stands(): void
    {
        $this->ship(1, "one\ntwo\n");
        $this->ship(2, "three\n");

        $this->actingAs($this->admin)->getJson(route('jobs.log', $this->job))->assertOk()->assertExactJson([
            'data' => "one\ntwo\nthree\n", 'offset' => 0, 'next_offset' => 14, 'size' => 14, 'last_seq' => 2, 'finalized' => false, 'truncated' => false,
        ]);

        // From an offset: the window starts on a line boundary.
        $this->getJson(route('jobs.log', [$this->job, 'offset' => 5]))->assertOk()->assertJson(['data' => "three\n", 'offset' => 8, 'next_offset' => 14]);
        $this->getJson(route('jobs.log', [$this->job, 'offset' => -7]))->assertOk()->assertJson(['data' => "three\n"]);
        $this->getJson(route('jobs.log', [$this->job, 'offset' => 14]))->assertOk()->assertJson(['data' => '', 'next_offset' => 14]);
        $this->getJson(route('jobs.log', [$this->job, 'limit' => 4]))->assertOk()->assertJson(['data' => "one\n", 'next_offset' => 4]);
    }

    public function test_scrollback_and_download_work_for_a_finalized_log(): void
    {
        $this->ship(1, "one\ntwo\n");
        $this->ship(2, '', true);

        $this->actingAs($this->admin)->getJson(route('jobs.log', [$this->job, 'offset' => 4]))->assertOk()
            ->assertJson(['data' => "two\n", 'finalized' => true, 'last_seq' => 2]);

        $plain = $this->get(route('jobs.log.download', $this->job))->assertOk();
        $this->assertSame("one\ntwo\n", $plain->streamedContent());
        $this->assertStringContainsString('text/plain', (string) $plain->headers->get('Content-Type'));

        $gz = $this->get(route('jobs.log.download', [$this->job, 'format' => 'gz']))->assertOk();
        $this->assertSame("one\ntwo\n", gzdecode((string) file_get_contents($gz->baseResponse->getFile()->getPathname())));
    }

    public function test_download_never_contains_a_known_secret(): void
    {
        $this->app->bind(SecretSource::class, fn () => new class implements SecretSource
        {
            public function forRunner(string $runnerId): array
            {
                return ['s3cr3t-value'];
            }
        });
        $this->ship(1, "password is s3cr3t-value\n");

        $text = $this->actingAs($this->admin)->get(route('jobs.log.download', $this->job))->streamedContent();
        $this->assertSame("password is ***\n", $text);
    }

    public function test_a_job_without_a_log_yields_an_empty_one(): void
    {
        $other = WorkflowJob::factory()->create();

        $this->actingAs($this->admin)->getJson(route('jobs.log', $other))->assertOk()->assertJson(['data' => '', 'size' => 0]);
        $this->assertSame('', $this->get(route('jobs.log.download', $other))->streamedContent());
        $this->getJson('/jobs/not-a-uuid/log')->assertNotFound();
    }

    public function test_viewers_read_logs_of_granted_repositories_only(): void
    {
        $this->ship(1, "one\n");
        $installation = Installation::factory()->create();
        $mine = WorkflowJob::factory()->create(['repository_id' => Repository::factory()->create(['installation_id' => $installation->id])->id]);
        $viewer = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($viewer, Role::Viewer);
        $team->grant($installation);
        [$plain] = app(CreateApiToken::class)($viewer, 'test', Role::Viewer);
        $token = ['Authorization' => "Bearer {$plain}"];

        $this->actingAs($viewer)->getJson(route('jobs.log', $this->job))->assertNotFound();
        $this->getJson(route('jobs.log', $mine))->assertOk();

        $this->getJson("/api/v1/jobs/{$this->job->id}/logs", $token)->assertNotFound();
        $this->get("/api/v1/jobs/{$mine->id}/logs", $token)->assertOk();
        $this->getJson('/api/v1/jobs', $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/v1/jobs/{$this->job->id}", $token)->assertNotFound();
    }

    public function test_api_job_list_filters_and_paginates(): void
    {
        [$plain] = app(CreateApiToken::class)($this->admin, 'test', Role::Viewer);
        $token = ['Authorization' => "Bearer {$plain}"];
        $repo = Repository::factory()->create(['full_name' => 'acme/api']);
        WorkflowJob::factory()->count(3)->create(['repository_id' => $repo->id]);
        WorkflowJob::factory()->completed()->create(['repository_id' => $repo->id]);

        $this->getJson('/api/v1/jobs?repository=acme/api&status=queued&per_page=2', $token)->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.repository', 'acme/api')->assertJsonStructure(['meta' => ['next_cursor']]);
        $this->getJson('/api/v1/jobs?status=completed', $token)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jobs?status=nope', $token)->assertStatus(422);
    }

    public function test_prune_deletes_expired_logs_and_old_jobs_but_not_recent_ones(): void
    {
        config(['jogger.job_retention_days' => 90]);
        $this->ship(1, "one\n", true);
        $log = JobLog::query()->findOrFail($this->runner->id);

        // Not expired, job recent: nothing goes.
        $this->artisan('jogger:prune')->assertSuccessful();
        $this->assertNotNull(JobLog::query()->find($this->runner->id));

        // Expired log: file and row go, the job stays.
        $log->update(['expires_at' => now()->subMinute()]);
        $this->artisan('jogger:prune')->assertSuccessful();
        $this->assertNull(JobLog::query()->find($this->runner->id));
        Storage::disk('logs')->assertMissing((string) $log->storage_path);
        $this->assertNotNull($this->job->fresh());

        // Jobs: only completed ones older than the retention, together with their log files.
        $old = WorkflowJob::factory()->completed()->create(['completed_at' => now()->subDays(91)]);
        $oldRunner = Runner::factory()->create(['host_id' => $this->host->id]);
        $old->update(['runner_id' => $oldRunner->id]);
        app(LogIngestor::class)->ingest($this->host, $oldRunner->id, 1, "old\n", true);
        $oldLog = JobLog::query()->findOrFail($oldRunner->id);
        $young = WorkflowJob::factory()->completed()->create(['completed_at' => now()->subDays(89)]);
        $stuck = WorkflowJob::factory()->create(['created_at' => now()->subDays(200)]);

        $this->artisan('jogger:prune')->assertSuccessful();

        $this->assertNull($old->fresh());
        Storage::disk('logs')->assertMissing((string) $oldLog->storage_path);
        $this->assertNotNull($young->fresh());
        $this->assertNotNull($stuck->fresh());
    }
}
