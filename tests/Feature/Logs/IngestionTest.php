<?php

declare(strict_types=1);

namespace Tests\Feature\Logs;

use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Logs\LogStore;
use App\Domain\Jobs\Logs\SecretSource;
use App\Domain\Jobs\Models\JobLog;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Runners\Contracts\RunnerEvents;
use App\Domain\Runners\Models\Runner;
use App\Events\LogChunkReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private string $runner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('logs');
        config(['jogger.log_disk' => 'logs']);
        $this->host = Host::factory()->create(['credential_hash' => Host::hashCredential('jha_one')]);
        $this->runner = (string) Str::uuid7();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ship(array $body, string $credential = 'jha_one'): TestResponse
    {
        $response = $this->withToken($credential)->postJson("/agent/v1/runners/{$this->runner}/logs", $body);
        $this->flushHeaders();

        return $response;
    }

    private function text(): string
    {
        return app(LogStore::class)->read(JobLog::query()->findOrFail($this->runner));
    }

    public function test_batches_are_appended_and_duplicates_ignored(): void
    {
        $this->ship(['seq' => 1, 'data' => "one\n"])->assertOk()->assertJson(['accepted' => true, 'last_seq' => 1]);
        $this->ship(['seq' => 2, 'data' => "two\n"])->assertOk();
        $this->ship(['seq' => 2, 'data' => "two\n"])->assertOk()->assertJson(['accepted' => false, 'last_seq' => 2]);
        $this->ship(['seq' => 1, 'data' => "one\n"])->assertOk()->assertJson(['accepted' => false]);

        $this->assertSame("one\ntwo\n", $this->text());
        $log = JobLog::query()->findOrFail($this->runner);
        $this->assertSame(8, $log->size_bytes);
        $this->assertSame(2, $log->line_count);
    }

    public function test_a_gap_is_accepted_with_a_marker(): void
    {
        $this->ship(['seq' => 1, 'data' => "one\n"]);
        $this->ship(['seq' => 4, 'data' => "four\n"])->assertOk()->assertJson(['accepted' => true, 'last_seq' => 4]);

        $this->assertSame("one\n[jogger: 2 log batch(es) missing]\nfour\n", $this->text());
    }

    public function test_secrets_are_masked_before_storage_and_broadcast(): void
    {
        Event::fake([LogChunkReceived::class]);
        $this->app->bind(SecretSource::class, fn () => new class implements SecretSource
        {
            public function forRunner(string $runnerId): array
            {
                return ['hunter2-token', 'ab'];
            }
        });
        Runner::factory()->create(['id' => $this->runner, 'host_id' => $this->host->id]);
        $job = WorkflowJob::factory()->create(['runner_id' => $this->runner]);

        $this->ship(['seq' => 1, 'data' => 'token=hunter2-token b64='.base64_encode('hunter2-token')." ab\n"]);

        $this->assertSame("token=*** b64=*** ab\n", $this->text());
        Event::assertDispatched(LogChunkReceived::class, fn (LogChunkReceived $e) => $e->jobId === $job->id
            && $e->data === "token=*** b64=*** ab\n" && $e->broadcastOn()[0]->name === "private-job.{$job->id}");
        $this->assertSame($job->id, JobLog::query()->findOrFail($this->runner)->job_id);
    }

    public function test_nothing_is_broadcast_before_the_job_is_known(): void
    {
        Event::fake([LogChunkReceived::class]);
        $this->ship(['seq' => 1, 'data' => "hello\n"])->assertOk();
        Event::assertNotDispatched(LogChunkReceived::class);
    }

    public function test_log_over_the_limit_is_truncated_with_a_marker(): void
    {
        config(['jogger.log_max_bytes_per_job' => 10]);

        $this->ship(['seq' => 1, 'data' => "12345\n"]);
        $this->ship(['seq' => 2, 'data' => "abcdefgh\n"])->assertJson(['truncated' => true]);
        $this->ship(['seq' => 3, 'data' => "more\n"])->assertJson(['accepted' => true, 'last_seq' => 3]);

        $this->assertSame("12345\nabcd\n[jogger: log truncated at 10 bytes]\n", $this->text());
        $this->assertTrue(JobLog::query()->findOrFail($this->runner)->truncated);
    }

    public function test_eof_gzips_the_log_and_sets_expiry(): void
    {
        config(['jogger.log_retention_days' => 7]);
        $this->ship(['seq' => 1, 'data' => "one\n"]);
        $this->ship(['seq' => 2, 'data' => '', 'eof' => true])->assertOk();

        $log = JobLog::query()->findOrFail($this->runner);
        $this->assertNotNull($log->finalized_at);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $log->expires_at->timestamp, 5);
        $this->assertStringEndsWith('.log.gz', (string) $log->storage_path);
        Storage::disk('logs')->assertExists($log->storage_path);
        Storage::disk('logs')->assertMissing("live/{$this->runner}.log");
        $this->assertSame("one\n", $this->text());

        // A closed log takes nothing more.
        $this->ship(['seq' => 3, 'data' => "late\n"])->assertJson(['accepted' => false]);
        $this->assertSame("one\n", $this->text());
    }

    public function test_another_host_cannot_write_to_the_log(): void
    {
        Host::factory()->create(['credential_hash' => Host::hashCredential('jha_two')]);
        $this->ship(['seq' => 1, 'data' => "one\n"]);

        $this->ship(['seq' => 2, 'data' => "evil\n"], 'jha_two')->assertNotFound();
        $this->ship(['seq' => 2, 'data' => "x\n"], 'jha_nope')->assertUnauthorized();
        $this->ship(['seq' => 0, 'data' => 'x'])->assertStatus(422);
    }

    public function test_flush_closes_idle_logs_and_prune_deletes_expired_ones(): void
    {
        $this->ship(['seq' => 1, 'data' => "one\n"]);
        JobLog::query()->whereKey($this->runner)->update(['last_chunk_at' => now()->subHour()]);

        $this->artisan('jogger:flush-logs')->assertSuccessful();
        $log = JobLog::query()->findOrFail($this->runner);
        $this->assertNotNull($log->finalized_at);

        $log->update(['expires_at' => now()->subMinute()]);
        $this->artisan('jogger:prune')->assertSuccessful();
        $this->assertNull(JobLog::query()->find($this->runner));
        Storage::disk('logs')->assertMissing((string) $log->storage_path);
    }

    public function test_container_events_reach_the_runner_handler(): void
    {
        $spy = new class implements RunnerEvents
        {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function containerStarted(Host $host, string $runnerId): void
            {
                $this->calls[] = ['started', $runnerId];
            }

            public function containerExited(Host $host, string $runnerId, ?int $exitCode, bool $oom): void
            {
                $this->calls[] = ['exited', $runnerId, $exitCode, $oom];
            }
        };
        $this->app->instance(RunnerEvents::class, $spy);

        $post = fn (array $body) => tap($this->withToken('jha_one')->postJson('/agent/v1/events', $body), fn () => $this->flushHeaders());

        $post(['type' => 'container_started', 'runner_id' => $this->runner, 'occurred_at' => now()->toIso8601String()])
            ->assertStatus(202)->assertJson(['handled' => true]);
        $post(['type' => 'container_exited', 'runner_id' => $this->runner, 'data' => ['exit_code' => 137, 'oom' => true]])->assertStatus(202);
        $post(['type' => 'something_new', 'runner_id' => $this->runner])->assertStatus(202)->assertJson(['handled' => false]);
        $post(['type' => 'container_exited', 'runner_id' => 'nope'])->assertStatus(422);

        $this->assertSame([['started', $this->runner], ['exited', $this->runner, 137, true]], $spy->calls);
    }
}
