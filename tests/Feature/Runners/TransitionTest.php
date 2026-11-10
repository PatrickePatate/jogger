<?php

declare(strict_types=1);

namespace Tests\Feature\Runners;

use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Actions\DrainHost;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Runners\Actions\TerminateRunner;
use App\Domain\Runners\Actions\TransitionRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Events\RunnerStateChanged;
use App\Support\Exceptions\GithubApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransitionTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private FakeGithubGateway $github;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->github = FakeGithubGateway::install();
        $this->host = Host::factory()->create(['status' => HostStatus::Online, 'credential_hash' => Host::hashCredential('jha_one')]);
    }

    private function runner(RunnerState $state, ?int $githubId = 77): Runner
    {
        return Runner::factory()->create(['state' => $state, 'host_id' => $this->host->id, 'github_runner_id' => $githubId]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function agentEvent(string $type, Runner $runner, array $data = []): void
    {
        $this->withToken('jha_one')->postJson('/agent/v1/events', ['type' => $type, 'runner_id' => $runner->id, 'data' => $data])->assertStatus(202);
        $this->flushHeaders();
    }

    public function test_a_stale_event_changes_nothing_and_broadcasts_nothing(): void
    {
        Event::fake([RunnerStateChanged::class]);
        $runner = $this->runner(RunnerState::Busy);
        $transition = app(TransitionRunner::class);

        $this->assertFalse($transition($runner, RunnerState::Idle));
        $this->assertSame(RunnerState::Busy, $runner->refresh()->state);
        Event::assertNotDispatched(RunnerStateChanged::class);

        $this->assertTrue($transition($runner, RunnerState::Terminating));
        $this->assertFalse($transition($runner, RunnerState::Terminating));
        Event::assertDispatchedTimes(RunnerStateChanged::class, 1);
    }

    public function test_container_started_makes_a_provisioning_runner_idle(): void
    {
        $runner = $this->runner(RunnerState::Provisioning);
        $this->agentEvent('container_started', $runner);
        $this->agentEvent('container_started', $runner);

        $runner->refresh();
        $this->assertSame(RunnerState::Idle, $runner->state);
        $this->assertNotNull($runner->started_at);
    }

    public function test_container_exit_ends_the_runner_with_reason_and_code(): void
    {
        $clean = $this->runner(RunnerState::Busy);
        $this->agentEvent('container_exited', $clean, ['exit_code' => 0]);
        $clean->refresh();
        $this->assertSame([RunnerState::Gone, 'completed', 0], [$clean->state, $clean->exit_reason, $clean->exit_code]);
        $this->assertNotNull($clean->finished_at);

        $crashed = $this->runner(RunnerState::Busy);
        $this->agentEvent('container_exited', $crashed, ['exit_code' => 2]);
        $crashed->refresh();
        $this->assertSame([RunnerState::Failed, 'container_exited', 2], [$crashed->state, $crashed->exit_reason, $crashed->exit_code]);

        $oom = $this->runner(RunnerState::Busy);
        $this->agentEvent('container_exited', $oom, ['exit_code' => 137, 'oom' => true]);
        $this->assertSame('oom', $oom->refresh()->exit_reason);

        $stopped = $this->runner(RunnerState::Terminating);
        $stopped->update(['exit_reason' => 'idle_timeout']);
        $this->agentEvent('container_exited', $stopped, ['exit_code' => 137]);
        $stopped->refresh();
        $this->assertSame([RunnerState::Gone, 'idle_timeout', 137], [$stopped->state, $stopped->exit_reason, $stopped->exit_code]);

        // A duplicate exit report is harmless, and busy runners are not deregistered (GitHub does that itself).
        $this->agentEvent('container_exited', $crashed, ['exit_code' => 9]);
        $this->assertSame(2, $crashed->refresh()->exit_code);
        $this->github->assertNotCalled('deleteRunner');

        // One that never took a job is still registered on GitHub.
        $idle = $this->runner(RunnerState::Idle, 88);
        $this->agentEvent('container_exited', $idle, ['exit_code' => 1]);
        $this->github->assertCalled('deleteRunner');
    }

    public function test_events_from_another_host_are_ignored(): void
    {
        $other = Host::factory()->create();
        $runner = Runner::factory()->create(['state' => RunnerState::Busy, 'host_id' => $other->id]);
        $this->agentEvent('container_exited', $runner, ['exit_code' => 1]);
        $this->assertSame(RunnerState::Busy, $runner->refresh()->state);
    }

    public function test_terminate_stops_the_container_and_deregisters(): void
    {
        $runner = $this->runner(RunnerState::Idle);

        $this->assertTrue(app(TerminateRunner::class)($runner, 'idle_timeout'));

        $runner->refresh();
        $this->assertSame([RunnerState::Terminating, 'idle_timeout'], [$runner->state, $runner->exit_reason]);
        $command = HostCommand::query()->where('host_id', $this->host->id)->firstOrFail();
        $this->assertSame('runner.stop', $command->type);
        $this->assertSame(['runner_id' => $runner->id], $command->payload);
        $this->assertSame(77, $this->github->callsTo('deleteRunner')[0]['runnerId']);

        // Again: nothing more happens.
        $this->assertFalse(app(TerminateRunner::class)($runner));
        $this->assertSame(1, HostCommand::query()->count());
    }

    public function test_terminate_survives_github_errors_and_unplaced_runners(): void
    {
        $this->github->failNextWith(new GithubApiError('boom'));
        $runner = $this->runner(RunnerState::Busy);
        $this->assertTrue(app(TerminateRunner::class)($runner));
        $this->assertSame(RunnerState::Terminating, $runner->refresh()->state);

        $unplaced = Runner::factory()->create(['state' => RunnerState::Provisioning]);
        app(TerminateRunner::class)($unplaced);
        $this->assertSame(RunnerState::Gone, $unplaced->refresh()->state);
    }

    public function test_agent_gets_the_full_list_of_runners_it_should_have(): void
    {
        $keep = [$this->runner(RunnerState::Provisioning)->id, $this->runner(RunnerState::Busy)->id, $this->runner(RunnerState::Terminating)->id];
        $this->runner(RunnerState::Gone);
        Runner::factory()->create(['state' => RunnerState::Idle, 'host_id' => Host::factory()->create()->id]);

        $listed = $this->withToken('jha_one')->getJson('/agent/v1/runners')->assertOk()->json('runners');
        sort($keep);
        $this->assertSame($keep, $listed);
    }

    public function test_a_draining_host_cordons_when_its_last_runner_ends(): void
    {
        $runner = $this->runner(RunnerState::Busy);
        app(DrainHost::class)($this->host);
        $this->assertSame(HostStatus::Draining, $this->host->refresh()->status);

        $this->agentEvent('container_exited', $runner, ['exit_code' => 0]);
        $this->assertSame(HostStatus::Cordoned, $this->host->refresh()->status);
    }
}
