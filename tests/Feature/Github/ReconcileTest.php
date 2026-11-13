<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Console\Commands\Reconcile;
use App\Domain\Github\Data\RunnerData;
use App\Domain\Github\Data\WorkflowJobData;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $github;

    private RunnerPool $pool;

    private Repository $repo;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([BroadcastEvent::class]);
        $this->github = FakeGithubGateway::install();
        $installation = Installation::factory()->create(['account_login' => 'octo-org']);
        $this->repo = Repository::factory()->create(['installation_id' => $installation->id, 'github_repo_id' => 186853002, 'full_name' => 'octo-org/example-workflow']);
        $this->pool = RunnerPool::factory()->create([
            'installation_id' => $installation->id, 'scope' => 'repository', 'scope_target' => 'octo-org/example-workflow',
            'labels' => ['self-hosted', 'linux', 'x64', 'jogger-large'],
        ]);
        $this->host = Host::factory()->create(['cpu_used' => 0, 'memory_used_mb' => 0]);
    }

    public function test_a_queued_job_missing_locally_is_imported_and_scheduled(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/github/workflow_job.queued.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->github->queuedJobs[] = WorkflowJobData::from($payload);

        $this->artisan('jogger:reconcile')->expectsOutputToContain('Imported 1 job(s)')->assertSuccessful();

        $job = WorkflowJob::query()->sole();
        $this->assertSame($this->pool->id, $job->pool_id);
        $this->assertSame(1, Runner::query()->inState(RunnerState::Provisioning)->count());

        // The next pass finds nothing new and starts nothing more.
        $this->artisan('jogger:reconcile')->expectsOutputToContain('Imported 0 job(s)')->assertSuccessful();
        $this->assertSame(1, Runner::query()->count());
    }

    public function test_unknown_and_finished_jogger_runners_are_deleted_from_github(): void
    {
        $live = Runner::factory()->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Idle, 'github_runner_id' => 1]);
        $done = Runner::factory()->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Gone, 'github_runner_id' => 2]);
        $this->github->runners = [
            1 => new RunnerData(1, $live->name, 'online'),
            2 => new RunnerData(2, $done->name, 'offline'),
            3 => new RunnerData(3, 'jogger-stranger-abc', 'offline'),
            4 => new RunnerData(4, 'someone-elses-runner', 'online'),
        ];

        $this->artisan('jogger:reconcile')->expectsOutputToContain('deleted 2 GitHub runner(s)')->assertSuccessful();

        $this->assertSame([1, 4], array_keys($this->github->runners));
        $this->assertSame(RunnerState::Idle, $live->refresh()->state);
    }

    public function test_a_runner_missing_on_github_for_five_minutes_is_failed_and_stopped(): void
    {
        $missing = Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $this->host->id, 'state' => RunnerState::Idle, 'github_runner_id' => 9, 'state_changed_at' => now()->subMinutes(6)]);
        $recent = Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $this->host->id, 'state' => RunnerState::Idle, 'github_runner_id' => 10, 'state_changed_at' => now()->subMinute()]);

        $this->artisan('jogger:reconcile')->expectsOutputToContain('failed 1 runner(s)')->assertSuccessful();

        $missing->refresh();
        $this->assertSame([RunnerState::Failed, 'github_missing'], [$missing->state, $missing->exit_reason]);
        $this->assertSame(RunnerState::Idle, $recent->refresh()->state);
        $command = HostCommand::query()->sole();
        $this->assertSame(['runner.stop', ['runner_id' => $missing->id]], [$command->type, $command->payload]);
    }

    public function test_an_overlapping_reconcile_exits_immediately(): void
    {
        $held = Cache::lock(Reconcile::LOCK, 300);
        $this->assertTrue($held->get());

        $this->artisan('jogger:reconcile')->expectsOutputToContain('Another reconcile is running')->assertSuccessful();
        $this->github->assertNotCalled('listRunners');

        $held->release();
        $this->artisan('jogger:reconcile')->assertSuccessful();
        $this->github->assertCalled('listRunners');
    }

    public function test_a_running_job_that_github_says_is_completed_is_closed(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/github/workflow_job.completed.json')), true, flags: JSON_THROW_ON_ERROR);
        $data = WorkflowJobData::from($payload);
        $this->repo->update(['github_repo_id' => $data->repositoryId]);
        $job = WorkflowJob::factory()->running()->create([
            'repository_id' => $this->repo->id, 'github_job_id' => $data->jobId, 'pool_id' => $this->pool->id,
            'updated_at' => now()->subMinutes(10),
        ]);
        $this->github->jobs[$data->jobId] = $data;

        $this->artisan('jogger:reconcile')->assertSuccessful();

        $this->assertSame('completed', $job->refresh()->status->value);
    }

    public function test_a_running_job_github_no_longer_knows_is_cancelled(): void
    {
        $job = WorkflowJob::factory()->running()->create([
            'repository_id' => $this->repo->id, 'pool_id' => $this->pool->id, 'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan('jogger:reconcile')->assertSuccessful();

        $job->refresh();
        $this->assertSame('completed', $job->status->value);
        $this->assertSame('cancelled', $job->conclusion->value);
    }

    public function test_a_recently_updated_job_is_left_alone(): void
    {
        WorkflowJob::factory()->running()->create(['repository_id' => $this->repo->id, 'pool_id' => $this->pool->id]);

        $this->artisan('jogger:reconcile')->assertSuccessful();

        $this->github->assertNotCalled('getJob');
    }
}
