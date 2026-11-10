<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Runners\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RunnersTest extends TestCase
{
    use RefreshDatabase;

    private RunnerPool $pool;

    private Host $host;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeGithubGateway::install();
        $this->pool = RunnerPool::factory()->create(['installation_id' => Installation::factory()->create()->id, 'name' => 'linux-pool']);
        $this->host = Host::factory()->create(['name' => 'build-01']);
        $this->admin = User::factory()->admin()->create();
    }

    private function runner(RunnerState $state, string $name): Runner
    {
        return Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $this->host->id, 'state' => $state, 'name' => $name, 'github_runner_id' => 5]);
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, $role);
        $team->grant($this->pool->installation);

        return $user;
    }

    public function test_terminating_an_idle_runner_runs_the_action_and_is_audited(): void
    {
        $runner = $this->runner(RunnerState::Idle, 'jogger-idle-one');

        Livewire::actingAs($this->member(Role::Operator))->test(Index::class)
            ->assertSee('jogger-idle-one')->assertSee('linux-pool')->assertSee('build-01')
            ->call('terminate', $runner->id)->assertSee('Terminating');

        $this->assertSame([RunnerState::Terminating, 'terminated_by_user'], [$runner->refresh()->state, $runner->exit_reason]);
        $this->assertSame('runner.stop', HostCommand::query()->sole()->type);
        $audit = AuditLog::query()->where('action', 'runner.terminated')->sole();
        $this->assertSame($runner->id, $audit->target_id);
        $this->assertFalse($audit->metadata['forced']);
    }

    public function test_a_busy_runner_needs_an_explicit_second_confirmation(): void
    {
        $runner = $this->runner(RunnerState::Busy, 'jogger-busy-one');
        WorkflowJob::factory()->running()->create(['runner_id' => $runner->id, 'job_name' => 'deploy-prod']);

        $page = Livewire::actingAs($this->admin)->test(Index::class)->assertSee('deploy-prod')
            ->call('terminate', $runner->id)->assertSee('This kills the running job.')->assertSee('Terminate anyway');
        $this->assertSame(RunnerState::Busy, $runner->refresh()->state);

        $page->call('cancelForce')->assertDontSee('Terminate anyway');
        $page->call('terminate', $runner->id)->call('terminate', $runner->id, true);
        $this->assertSame(RunnerState::Terminating, $runner->refresh()->state);
        $this->assertTrue(AuditLog::query()->where('action', 'runner.terminated')->sole()->metadata['forced']);
    }

    public function test_the_list_follows_state_changes_and_shows_the_lifecycle(): void
    {
        $runner = $this->runner(RunnerState::Provisioning, 'jogger-new-one');
        $old = $this->runner(RunnerState::Gone, 'jogger-old-one');
        $old->update(['finished_at' => now()->subDays(2)]);
        $recent = $this->runner(RunnerState::Failed, 'jogger-recent-one');
        $recent->update(['finished_at' => now()->subHour(), 'exit_reason' => 'provision_timeout']);

        $page = Livewire::actingAs($this->admin)->test(Index::class)
            ->assertSee('jogger-new-one')->assertSee('Provisioning')->assertSee('jogger-recent-one')->assertSee('provision timeout')->assertDontSee('jogger-old-one');
        $this->assertArrayHasKey('echo-private:runners,.runner.state-changed', $page->instance()->getListeners());

        $runner->update(['state' => RunnerState::Idle, 'started_at' => now()]);
        $page->dispatch('echo-private:runners,.runner.state-changed')->assertSee('Idle');

        $page->call('select', $runner->id)->assertSee('Lifecycle of jogger-new-one')->assertSee('Waiting for a job');
        $page->set('state', 'gone')->assertSee('jogger-old-one')->assertDontSee('jogger-new-one');
    }

    public function test_viewers_see_no_actions_and_cannot_terminate(): void
    {
        $runner = $this->runner(RunnerState::Idle, 'jogger-idle-one');
        $viewer = $this->member(Role::Viewer);

        Livewire::actingAs($viewer)->test(Index::class)->assertSee('jogger-idle-one')->assertDontSee('Terminate')
            ->assertSeeHtml('wire:poll.5s')
            ->call('terminate', $runner->id)->assertForbidden();
        $this->assertSame(RunnerState::Idle, $runner->refresh()->state);

        // No grant at all: the runner is not even listed.
        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertDontSee('jogger-idle-one')->assertSee('No runners');
    }
}
