<?php

declare(strict_types=1);

namespace Tests\Feature\Runners;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolDemand;
use App\Domain\Runners\Actions\ProvisionRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Jobs\ProvisionRunnerJob;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\HostUnavailable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProvisionTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $github;

    private RunnerPool $pool;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        // Only broadcasts are faked; ProvisionRunnerJob runs for real.
        Queue::fake([BroadcastEvent::class]);
        $this->github = FakeGithubGateway::install();
        $this->pool = RunnerPool::factory()->create([
            'installation_id' => Installation::factory()->create(['account_login' => 'acme'])->id,
            'name' => 'Linux x64', 'max_runners' => 2, 'cpu_limit' => 2, 'memory_limit_mb' => 4096, 'image' => 'ghcr.io/acme/runner:1',
        ]);
        $this->host = Host::factory()->create(['cpu_used' => 0, 'memory_used_mb' => 0]);
    }

    public function test_provisioning_creates_one_runner_and_one_start_command(): void
    {
        $runner = app(ProvisionRunner::class)($this->pool);

        $this->assertNotNull($runner);
        $this->assertSame(1, Runner::query()->count());
        $this->assertSame(RunnerState::Provisioning, $runner->state);
        $this->assertSame($this->host->id, $runner->host_id);
        $this->assertSame(1000, $runner->github_runner_id);
        $this->assertStringStartsWith('jogger-linux-x64-', $runner->name);

        $call = $this->github->callsTo('generateJitConfig')[0];
        $this->assertSame($runner->name, $call['name']);
        $this->assertSame(['self-hosted', 'linux', 'x64'], $call['labels']);
        $this->assertSame('acme', $call['scope']->target);

        $command = HostCommand::query()->sole();
        $this->assertSame('runner.start', $command->type);
        $this->assertSame($this->host->id, $command->host_id);
        $this->assertEquals([
            'runner_id' => $runner->id, 'name' => $runner->name, 'image' => 'ghcr.io/acme/runner:1', 'jit_config' => 'fake-jit-config-1000',
            'cpu' => 2, 'memory_mb' => 4096, 'env' => [], 'labels' => ['self-hosted', 'linux', 'x64'], 'docker_mode' => 'none',
        ], $command->payload);
    }

    public function test_the_jit_config_is_scrubbed_once_the_command_is_acknowledged(): void
    {
        $this->host->update(['credential_hash' => Host::hashCredential('jha_one')]);
        app(ProvisionRunner::class)($this->pool);
        $command = HostCommand::query()->sole();

        $this->withToken('jha_one')->postJson("/agent/v1/commands/{$command->id}/ack", ['ok' => true])->assertOk();

        $this->assertArrayNotHasKey('jit_config', $command->refresh()->payload);
        $this->assertArrayHasKey('image', $command->payload);
    }

    public function test_at_max_runners_nothing_is_created_and_github_is_not_called(): void
    {
        Runner::factory()->count(2)->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Busy]);
        Runner::factory()->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Gone]);

        $this->assertNull(app(ProvisionRunner::class)($this->pool));
        $this->pool->update(['max_runners' => 5, 'status' => 'paused']);
        $this->assertNull(app(ProvisionRunner::class)($this->pool));

        $this->github->assertNotCalled('generateJitConfig');
        $this->assertSame(0, HostCommand::query()->count());
    }

    public function test_jit_failure_marks_the_runner_failed_and_frees_the_slot(): void
    {
        $this->pool->update(['max_runners' => 1]);
        $this->github->failNextWith(new GithubApiError('nope', 500));

        try {
            app(ProvisionRunner::class)($this->pool);
            $this->fail('expected the GitHub error');
        } catch (GithubApiError) {
        }

        $failed = Runner::query()->sole();
        $this->assertSame([RunnerState::Failed, 'jit_failed'], [$failed->state, $failed->exit_reason]);
        $this->assertSame(0, HostCommand::query()->count());

        $this->assertNotNull(app(ProvisionRunner::class)($this->pool));
    }

    public function test_no_host_keeps_the_job_queued_with_a_reason_and_retries(): void
    {
        $this->host->update(['status' => 'offline']);
        $job = WorkflowJob::factory()->create(['pool_id' => $this->pool->id]);

        try {
            app(ProvisionRunner::class)($this->pool);
            $this->fail('expected HostUnavailable');
        } catch (HostUnavailable) {
        }
        $this->assertSame(0, Runner::query()->count());

        $queued = (new ProvisionRunnerJob($this->pool->id, $job->id))->withFakeQueueInteractions();
        $queued->handle(app(ProvisionRunner::class), app(PoolDemand::class));
        $queued->assertReleased(30);
        $this->assertStringStartsWith('NO_CAPACITY:', (string) $job->refresh()->unmatched_reason);
        $this->assertSame('queued', $job->status->value);

        // The host comes back: the retry succeeds and the reason is cleared.
        $this->host->update(['status' => 'online']);
        $retry = (new ProvisionRunnerJob($this->pool->id, $job->id))->withFakeQueueInteractions();
        $retry->handle(app(ProvisionRunner::class), app(PoolDemand::class));
        $retry->assertNotReleased();
        $this->assertNull($job->refresh()->unmatched_reason);
        $this->assertSame(1, Runner::query()->live()->count());
    }

    public function test_the_job_waits_on_full_pools_and_stops_for_finished_jobs(): void
    {
        $job = WorkflowJob::factory()->create(['pool_id' => $this->pool->id]);

        Runner::factory()->count(2)->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Busy]);
        $full = (new ProvisionRunnerJob($this->pool->id, $job->id))->withFakeQueueInteractions();
        $full->handle(app(ProvisionRunner::class), app(PoolDemand::class));
        $full->assertReleased(20);
        $this->assertStringContainsString('maximum', (string) $job->refresh()->unmatched_reason);

        $done = WorkflowJob::factory()->running()->create();
        $skip = (new ProvisionRunnerJob($this->pool->id, $done->id))->withFakeQueueInteractions();
        $skip->handle(app(ProvisionRunner::class), app(PoolDemand::class));
        $skip->assertNotReleased();
        $this->github->assertNotCalled('generateJitConfig');
    }
}
