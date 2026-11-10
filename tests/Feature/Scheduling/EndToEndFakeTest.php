<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Domain\Github\Handlers\HandleWorkflowJob;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Actions\TrimIdleRunners;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Jobs\TrimIdleRunnersJob;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EndToEndFakeTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $github;

    private Repository $repo;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([BroadcastEvent::class, TrimIdleRunnersJob::class]);
        $this->github = FakeGithubGateway::install();
        $installation = Installation::factory()->create(['account_login' => 'octo-org']);
        $this->repo = Repository::factory()->create(['installation_id' => $installation->id, 'github_repo_id' => 186853002, 'full_name' => 'octo-org/example-workflow']);
        $this->host = Host::factory()->create(['cpu_used' => 0, 'memory_used_mb' => 0, 'credential_hash' => Host::hashCredential('jha_one')]);
    }

    private function pool(): RunnerPool
    {
        return RunnerPool::factory()->create([
            'installation_id' => $this->repo->installation_id, 'scope_target' => 'octo-org', 'labels' => ['self-hosted', 'linux', 'x64', 'jogger-large'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $job  overrides for the workflow_job object
     */
    private function deliver(string $stage, array $job = []): void
    {
        $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/github/workflow_job.{$stage}.json")), true, flags: JSON_THROW_ON_ERROR);
        $payload['workflow_job'] = $job + $payload['workflow_job'];
        app(HandleWorkflowJob::class)->handle(WebhookDelivery::factory()->create(['event' => 'workflow_job', 'payload' => $payload]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function agentEvent(string $type, Runner $runner, array $data = []): void
    {
        $this->withToken('jha_one')->postJson('/agent/v1/events', ['type' => $type, 'runner_id' => $runner->id, 'data' => $data])->assertStatus(202);
        $this->flushHeaders();
    }

    public function test_full_cycle_from_queued_to_torn_down(): void
    {
        $pool = $this->pool();

        $this->deliver('queued');
        $job = WorkflowJob::query()->sole();
        $runner = Runner::query()->sole();
        $this->assertSame($pool->id, $job->pool_id);
        $this->assertNull($job->unmatched_reason);
        $this->assertSame(RunnerState::Provisioning, $runner->state);
        $this->assertSame('runner.start', HostCommand::query()->sole()->type);

        // Redelivery does not start a second runner.
        $this->deliver('queued');
        $this->assertSame(1, Runner::query()->count());

        $this->agentEvent('container_started', $runner);
        $this->assertSame(RunnerState::Idle, $runner->refresh()->state);

        $this->deliver('in_progress', ['runner_name' => $runner->name, 'runner_id' => $runner->github_runner_id]);
        $this->assertSame(RunnerState::Busy, $runner->refresh()->state);
        $this->assertSame($runner->id, $job->refresh()->runner_id);

        $this->deliver('completed', ['runner_name' => $runner->name, 'runner_id' => $runner->github_runner_id]);
        $this->assertSame(RunnerState::Terminating, $runner->refresh()->state);
        $this->assertSame('runner.stop', HostCommand::query()->latest('created_at')->orderByDesc('id')->firstOrFail()->type);
        $this->github->assertCalled('deleteRunner');

        $this->agentEvent('container_exited', $runner, ['exit_code' => 0]);
        $runner->refresh();
        $this->assertSame([RunnerState::Gone, 'completed'], [$runner->state, $runner->exit_reason]);
        $this->assertSame('completed', $job->refresh()->status->value);
    }

    public function test_completed_without_in_progress_still_tears_the_runner_down(): void
    {
        $this->pool();
        $this->deliver('queued');
        $runner = Runner::query()->sole();

        $this->deliver('completed', ['runner_name' => $runner->name]);

        $this->assertSame(RunnerState::Terminating, $runner->refresh()->state);
        $this->assertSame($runner->id, WorkflowJob::query()->sole()->runner_id);
    }

    public function test_self_hosted_job_without_a_pool_is_marked_unmatched(): void
    {
        $this->deliver('queued');

        $job = WorkflowJob::query()->sole();
        $this->assertNull($job->pool_id);
        $this->assertSame('NO_MATCHING_POOL: no pool has all of these labels: self-hosted, linux, x64, jogger-large', $job->unmatched_reason);
        $this->assertSame(0, Runner::query()->count());
        $this->github->assertNotCalled('generateJitConfig');
    }

    public function test_github_hosted_job_is_ignored(): void
    {
        $this->pool();
        $this->deliver('queued', ['labels' => ['ubuntu-latest']]);

        $job = WorkflowJob::query()->sole();
        $this->assertNull($job->pool_id);
        $this->assertNull($job->unmatched_reason);
        $this->assertSame(0, Runner::query()->count());
    }

    public function test_cancelled_while_queued_trims_the_unused_runner_within_a_minute(): void
    {
        $pool = $this->pool();
        $this->deliver('queued');
        $runner = Runner::query()->sole();
        $this->agentEvent('container_started', $runner);

        $this->deliver('completed', ['conclusion' => 'cancelled', 'runner_name' => null, 'runner_id' => null]);

        $this->assertSame(RunnerState::Idle, $runner->refresh()->state);
        Queue::assertPushed(TrimIdleRunnersJob::class, fn (TrimIdleRunnersJob $j) => $j->poolId === $pool->id && $j->delay <= 60 && $j->delay > 0);

        (new TrimIdleRunnersJob($pool->id))->handle(app(TrimIdleRunners::class));
        $runner->refresh();
        $this->assertSame([RunnerState::Terminating, 'job_cancelled'], [$runner->state, $runner->exit_reason]);
    }

    public function test_trim_keeps_runners_other_queued_jobs_and_the_warm_minimum_need(): void
    {
        $pool = $this->pool();
        $pool->update(['min_idle' => 1]);
        Runner::factory()->count(3)->create(['pool_id' => $pool->id, 'host_id' => $this->host->id, 'state' => RunnerState::Idle]);
        WorkflowJob::factory()->create(['repository_id' => $this->repo->id, 'pool_id' => $pool->id]);

        $this->assertSame(1, app(TrimIdleRunners::class)($pool));
        $this->assertSame(2, Runner::query()->inState(RunnerState::Idle)->count());
    }

    public function test_a_second_job_reuses_a_waiting_runner_only_if_one_is_spare(): void
    {
        $pool = $this->pool();
        Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $this->host->id, 'state' => RunnerState::Idle]);

        $this->deliver('queued');
        $this->assertSame(1, Runner::query()->count());
        $this->github->assertNotCalled('generateJitConfig');

        $this->deliver('queued', ['id' => 999001]);
        $this->assertSame(2, Runner::query()->count());
    }
}
