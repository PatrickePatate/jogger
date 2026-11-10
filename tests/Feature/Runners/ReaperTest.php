<?php

declare(strict_types=1);

namespace Tests\Feature\Runners;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Actions\ReapStuckRunners;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReaperTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $github;

    private RunnerPool $pool;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([BroadcastEvent::class]);
        $this->github = FakeGithubGateway::install();
        $installation = Installation::factory()->create(['account_login' => 'acme']);
        Repository::factory()->create(['installation_id' => $installation->id, 'full_name' => 'acme/api']);
        $this->pool = RunnerPool::factory()->create(['installation_id' => $installation->id, 'idle_ttl_seconds' => 600]);
        $this->host = Host::factory()->create(['cpu_used' => 0, 'memory_used_mb' => 0]);
    }

    private function runner(RunnerState $state, int $ageSeconds, ?Host $host = null): Runner
    {
        return Runner::factory()->create([
            'pool_id' => $this->pool->id, 'host_id' => ($host ?? $this->host)->id, 'state' => $state,
            'github_runner_id' => random_int(1, 999999), 'state_changed_at' => now()->subSeconds($ageSeconds),
        ]);
    }

    public function test_provision_timeout_fails_the_runner_and_reprovisions_for_the_waiting_job(): void
    {
        $stuck = $this->runner(RunnerState::Provisioning, 200);
        $fresh = $this->runner(RunnerState::Provisioning, 60);
        $job = WorkflowJob::factory()->create(['repository_id' => Repository::query()->sole()->id, 'pool_id' => $this->pool->id]);
        WorkflowJob::factory()->create(['repository_id' => $job->repository_id, 'pool_id' => $this->pool->id]);

        $result = app(ReapStuckRunners::class)();

        $this->assertSame(1, $result['provision_timeout']);
        $stuck->refresh();
        $this->assertSame([RunnerState::Failed, 'provision_timeout'], [$stuck->state, $stuck->exit_reason]);
        $this->assertSame(RunnerState::Provisioning, $fresh->refresh()->state);
        $this->github->assertCalled('deleteRunner');
        $this->assertSame(1, HostCommand::query()->where('type', 'runner.stop')->count());
        // Two jobs wait, one runner is still starting: one replacement is provisioned.
        $this->assertSame(1, HostCommand::query()->where('type', 'runner.start')->count());
        $this->assertSame(2, Runner::query()->inState(RunnerState::Provisioning)->count());

        // Idempotent.
        $again = app(ReapStuckRunners::class)();
        $this->assertSame(0, array_sum($again));
        $this->assertSame(2, HostCommand::query()->count());
    }

    public function test_runners_on_a_host_offline_for_five_minutes_are_lost(): void
    {
        $gone = Host::factory()->create(['status' => 'offline', 'last_seen_at' => now()->subMinutes(6)]);
        $blip = Host::factory()->create(['status' => 'offline', 'last_seen_at' => now()->subMinutes(2)]);
        $lost = $this->runner(RunnerState::Busy, 30, $gone);
        $stopping = $this->runner(RunnerState::Terminating, 30, $gone);
        $kept = $this->runner(RunnerState::Busy, 30, $blip);

        $this->assertSame(2, app(ReapStuckRunners::class)()['host_lost']);

        $this->assertSame([RunnerState::Failed, 'host_lost'], [$lost->refresh()->state, $lost->exit_reason]);
        $this->assertSame(RunnerState::Failed, $stopping->refresh()->state);
        $this->assertSame(RunnerState::Busy, $kept->refresh()->state);
        $this->assertSame(0, HostCommand::query()->count());
    }

    public function test_idle_runners_past_the_ttl_are_terminated_down_to_min_idle(): void
    {
        $this->pool->update(['min_idle' => 1]);
        $oldest = $this->runner(RunnerState::Idle, 900);
        $old = $this->runner(RunnerState::Idle, 700);
        $young = $this->runner(RunnerState::Idle, 60);

        $this->assertSame(2, app(ReapStuckRunners::class)()['idle_timeout']);
        $this->assertSame([RunnerState::Terminating, 'idle_timeout'], [$oldest->refresh()->state, $oldest->exit_reason]);
        $this->assertSame(RunnerState::Terminating, $old->refresh()->state);
        $this->assertSame(RunnerState::Idle, $young->refresh()->state);

        // With the minimum reached, even an expired idle runner stays.
        $young->update(['state_changed_at' => now()->subHour()]);
        $this->assertSame(0, app(ReapStuckRunners::class)()['idle_timeout']);
    }

    public function test_an_unconfirmed_stop_is_eventually_assumed_done(): void
    {
        $stuck = $this->runner(RunnerState::Terminating, 400);
        $recent = $this->runner(RunnerState::Terminating, 30);

        $this->artisan('jogger:reap-runners')->expectsOutputToContain('stop_unconfirmed: 1')->assertSuccessful();

        $this->assertSame(RunnerState::Gone, $stuck->refresh()->state);
        $this->assertSame(RunnerState::Terminating, $recent->refresh()->state);
    }
}
