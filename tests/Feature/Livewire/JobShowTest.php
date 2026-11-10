<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Jobs\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class JobShowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_steps_follow_the_job_as_events_arrive(): void
    {
        $pool = RunnerPool::factory()->create(['name' => 'linux-pool']);
        $job = WorkflowJob::factory()->create(['job_name' => 'unit-tests', 'pool_id' => $pool->id, 'queued_at' => now()->subSeconds(20)]);

        $page = Livewire::actingAs($this->admin)->test(Show::class, ['job' => $job->id])
            ->assertSee('unit-tests')->assertSee('Waiting for a runner for 20s')->assertSee('linux-pool')->assertSeeHtml('wire:poll.5s');
        $this->assertArrayHasKey("echo-private:job.{$job->id},.job.status-changed", $page->instance()->getListeners());

        $host = Host::factory()->create(['name' => 'build-01']);
        $runner = Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $host->id, 'state' => RunnerState::Busy, 'name' => 'jogger-linux-abc']);
        $job->update(['status' => 'in_progress', 'started_at' => now()->subSeconds(5), 'runner_id' => $runner->id]);
        $page->dispatch("echo-private:job.{$job->id},.job.status-changed")
            ->assertSee('Runner ready after 15s')->assertSee('Running for 5s on jogger-linux-abc')->assertSee('build-01')->assertSee('Busy');

        $job->update(['status' => 'completed', 'conclusion' => 'success', 'completed_at' => now()]);
        $page->dispatch("echo-private:job.{$job->id},.job.status-changed")
            ->assertSee('Ran for 5s')->assertSee('Success')->assertDontSeeHtml('wire:poll.5s');
    }

    public function test_failed_provisioning_shows_a_failed_step_with_the_reason(): void
    {
        $unmatched = WorkflowJob::factory()->create(['unmatched_reason' => 'NO_MATCHING_POOL: no pool has all of these labels: self-hosted, gpu']);
        Livewire::actingAs($this->admin)->test(Show::class, ['job' => $unmatched->id])
            ->assertSee('No pool has all of these labels: self-hosted, gpu')->assertSee('Failed')->assertSee('No matching pool');

        $pool = RunnerPool::factory()->create();
        $job = WorkflowJob::factory()->create(['pool_id' => $pool->id]);
        Runner::factory()->create(['pool_id' => $pool->id, 'state' => RunnerState::Failed, 'exit_reason' => 'provision_timeout']);
        Livewire::actingAs($this->admin)->test(Show::class, ['job' => $job->id])->assertSee('Runner failed to start (provision timeout); retrying');

        $failed = WorkflowJob::factory()->completed(JobConclusion::Failure)->create(['started_at' => now()->subMinute()]);
        Livewire::actingAs($this->admin)->test(Show::class, ['job' => $failed->id])->assertSee('Failure');
    }

    public function test_github_link_opens_in_a_new_tab_and_the_log_viewer_is_wired(): void
    {
        $job = WorkflowJob::factory()->create();

        Livewire::actingAs($this->admin)->test(Show::class, ['job' => $job->id])
            ->assertSeeHtml('href="'.$job->html_url.'"')->assertSeeHtml('target="_blank"')->assertSeeHtml('rel="noopener noreferrer"')
            ->assertSeeHtml('logTerminal(')->assertSeeHtml(route('jobs.log.download', $job));
    }

    public function test_invisible_jobs_are_not_found(): void
    {
        $job = WorkflowJob::factory()->create();

        Livewire::actingAs(User::factory()->create())->test(Show::class, ['job' => $job->id])->assertNotFound();
        $this->actingAs($this->admin)->get('/jobs/not-a-uuid')->assertNotFound();
        $this->get(route('jobs.show', $job))->assertOk();
        $this->get(route('jobs.index'))->assertOk();
    }
}
