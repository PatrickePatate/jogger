<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Support\GithubLane;
use App\Domain\Github\Support\RateLimitedByGithub;
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
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FailureInjectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $github;

    private RunnerPool $pool;

    private WorkflowJob $job;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([BroadcastEvent::class]);
        $this->github = FakeGithubGateway::install();
        $installation = Installation::factory()->create(['account_login' => 'acme']);
        $repo = Repository::factory()->create(['installation_id' => $installation->id, 'full_name' => 'acme/api']);
        $this->pool = RunnerPool::factory()->create(['installation_id' => $installation->id]);
        $this->job = WorkflowJob::factory()->create(['repository_id' => $repo->id, 'pool_id' => $this->pool->id]);
        $this->host = Host::factory()->create(['cpu_used' => 0, 'memory_used_mb' => 0, 'credential_hash' => Host::hashCredential('jha_one')]);
    }

    /** Runs the job through its lane middleware the way a queue worker would. */
    private function work(): ProvisionRunnerJob
    {
        $job = (new ProvisionRunnerJob($this->pool->id, $this->job->id))->withFakeQueueInteractions();
        app(RateLimitedByGithub::class)->handle($job, fn ($j) => $j->handle(app(ProvisionRunner::class), app(PoolDemand::class)));

        return $job;
    }

    public function test_a_429_pauses_the_lane_until_reset_and_work_resumes_by_itself(): void
    {
        $this->github->failNextWith(new GithubRateLimited('slow down', 90));

        $this->work()->assertReleased(91);
        $this->assertSame(90, app(GithubLane::class)->pausedFor($this->pool->installation_id));
        $this->assertStringStartsWith('GITHUB_RATE_LIMITED:', (string) $this->job->refresh()->unmatched_reason);
        $failed = Runner::query()->sole();
        $this->assertSame([RunnerState::Failed, 'jit_failed'], [$failed->state, $failed->exit_reason]);

        // While paused nothing reaches GitHub: not this job, not the warm-pool reconcile.
        $this->travel(30)->seconds();
        $this->work()->assertReleased(61);
        $this->artisan('jogger:reconcile-pools')->assertSuccessful();
        $this->github->assertCalled('generateJitConfig', 1);

        // After the reset the same job goes through.
        $this->travel(61)->seconds();
        $this->work()->assertNotReleased();
        $this->github->assertCalled('generateJitConfig', 2);
        $this->assertSame(1, Runner::query()->live()->count());
        $this->assertNull($this->job->refresh()->unmatched_reason);
    }

    public function test_a_github_5xx_fails_the_runner_with_a_reason_and_leaves_the_slot_free(): void
    {
        $this->github->failNextWith(new GithubApiError('bad gateway', 502));

        try {
            $this->work();
            $this->fail('the error should reach the queue worker so it retries with backoff');
        } catch (GithubApiError) {
        }

        $this->assertSame('jit_failed', Runner::query()->sole()->exit_reason);
        $this->assertSame(0, app(GithubLane::class)->pausedFor($this->pool->installation_id));
        $this->work()->assertNotReleased();
        $this->assertSame(1, Runner::query()->live()->count());
    }

    public function test_a_lost_start_ack_is_redelivered_as_the_same_command(): void
    {
        $this->work();
        $runner = Runner::query()->sole();
        $fetch = fn () => tap($this->withToken('jha_one')->getJson('/agent/v1/commands')->assertOk()->json('commands'), fn () => $this->flushHeaders());

        $first = $fetch();
        $this->assertCount(1, $first);
        $this->assertSame([], $fetch());

        // The agent started the container but its ack never arrived.
        $this->travel(HostCommand::REDELIVER_AFTER + 5)->seconds();
        $this->artisan('jogger:redeliver-commands')->assertSuccessful();
        $second = $fetch();

        // Same id and runner: the agent recognises the redelivery and does not start a second container.
        $this->assertSame($first[0]['id'], $second[0]['id']);
        $this->assertSame($runner->id, $second[0]['payload']['runner_id']);
        $this->assertSame(1, HostCommand::query()->count());
        $this->assertSame(1, Runner::query()->count());

        $this->withToken('jha_one')->postJson("/agent/v1/commands/{$first[0]['id']}/ack", ['ok' => true, 'result' => ['existing' => true]])->assertOk();
        $this->flushHeaders();
        $this->assertSame([], $fetch());
    }

    public function test_a_failed_start_fails_the_runner_at_once(): void
    {
        $this->work();
        $runner = Runner::query()->sole();
        $command = HostCommand::query()->sole();
        $this->withToken('jha_one')->getJson('/agent/v1/commands')->assertOk();

        $this->withToken('jha_one')->postJson("/agent/v1/commands/{$command->id}/ack", ['ok' => false, 'result' => ['error' => 'image not found']])->assertOk();

        $runner->refresh();
        $this->assertSame([RunnerState::Failed, 'start_failed'], [$runner->state, $runner->exit_reason]);
        $this->github->assertCalled('deleteRunner');
    }
}
