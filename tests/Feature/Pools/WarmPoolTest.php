<?php

declare(strict_types=1);

namespace Tests\Feature\Pools;

use App\Domain\Github\Handlers\HandleWorkflowJob;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmPoolTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $github;

    private RunnerPool $pool;

    private Repository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([BroadcastEvent::class]);
        $this->github = FakeGithubGateway::install();
        $installation = Installation::factory()->create(['account_login' => 'octo-org']);
        $this->repo = Repository::factory()->create(['installation_id' => $installation->id, 'github_repo_id' => 186853002, 'full_name' => 'octo-org/example-workflow']);
        $this->pool = RunnerPool::factory()->create([
            'installation_id' => $installation->id, 'scope_target' => 'octo-org', 'min_idle' => 2, 'max_runners' => 4,
            'labels' => ['self-hosted', 'linux', 'x64', 'jogger-large'],
        ]);
        Host::factory()->create(['cpu_total' => 64, 'memory_total_mb' => 128000, 'cpu_used' => 0, 'memory_used_mb' => 0]);
    }

    private function tick(): void
    {
        $this->artisan('jogger:reconcile-pools')->assertSuccessful();
    }

    private function waiting(): int
    {
        return Runner::query()->where('pool_id', $this->pool->id)->inState(RunnerState::Provisioning, RunnerState::Idle)->count();
    }

    public function test_pool_converges_to_min_idle_and_stays_there(): void
    {
        $this->tick();
        $this->assertSame(2, $this->waiting());

        $this->tick();
        $this->assertSame(2, $this->waiting());
        $this->github->assertCalled('generateJitConfig', 2);
    }

    public function test_a_queued_job_consumes_a_warm_runner_and_the_pool_rewarms(): void
    {
        $this->tick();
        Runner::query()->update(['state' => RunnerState::Idle->value]);

        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/github/workflow_job.queued.json')), true, flags: JSON_THROW_ON_ERROR);
        app(HandleWorkflowJob::class)->handle(WebhookDelivery::factory()->create(['event' => 'workflow_job', 'payload' => $payload]));

        // One runner per waiting job plus the warm minimum: exactly one more.
        $this->assertSame(3, $this->waiting());

        $taken = Runner::query()->inState(RunnerState::Idle)->firstOrFail();
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/github/workflow_job.in_progress.json')), true, flags: JSON_THROW_ON_ERROR);
        $payload['workflow_job']['runner_name'] = $taken->name;
        app(HandleWorkflowJob::class)->handle(WebhookDelivery::factory()->create(['event' => 'workflow_job', 'payload' => $payload]));

        $this->assertSame(RunnerState::Busy, $taken->refresh()->state);
        $this->assertSame(2, $this->waiting());
        $this->tick();
        $this->assertSame(2, $this->waiting());
    }

    public function test_never_exceeds_max_runners_and_scales_for_queued_jobs(): void
    {
        WorkflowJob::factory()->count(6)->create(['repository_id' => $this->repo->id, 'pool_id' => $this->pool->id]);

        $this->tick();
        $this->tick();

        $this->assertSame(4, Runner::query()->live()->count());
    }

    public function test_paused_pool_provisions_nothing_and_rate_limits_stop_the_pass(): void
    {
        $this->pool->update(['status' => 'paused']);
        $this->tick();
        $this->assertSame(0, Runner::query()->count());

        $this->pool->update(['status' => 'active']);
        $this->github->failNextWith(new GithubRateLimited('wait', 60));
        $this->tick();
        $this->assertSame(0, Runner::query()->live()->count());
        $this->github->assertCalled('generateJitConfig', 1);
    }
}
