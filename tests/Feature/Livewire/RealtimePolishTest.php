<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Hosts\Index as HostsIndex;
use App\Livewire\Pools\Form;
use App\Livewire\Pools\Index as PoolsIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RealtimePolishTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->admin()->create();
        $this->installation = Installation::factory()->create();
    }

    public function test_unmatched_jobs_are_grouped_by_label_set_and_counted(): void
    {
        $repo = Repository::factory()->create(['installation_id' => $this->installation->id]);
        $unmatched = ['repository_id' => $repo->id, 'status' => JobStatus::Queued, 'unmatched_reason' => 'NO_MATCHING_POOL: no pool serves these labels'];
        WorkflowJob::factory()->count(2)->create([...$unmatched, 'labels' => ['self-hosted', 'gpu']]);
        WorkflowJob::factory()->create([...$unmatched, 'labels' => ['gpu', 'self-hosted']]);
        WorkflowJob::factory()->create([...$unmatched, 'labels' => ['self-hosted', 'arm64']]);
        WorkflowJob::factory()->create(['repository_id' => $repo->id, 'status' => JobStatus::Queued, 'labels' => ['self-hosted', 'matched'], 'unmatched_reason' => 'NO_CAPACITY: pool is full']);

        Livewire::actingAs($this->admin)->test(PoolsIndex::class)
            ->assertViewHas('unmatched', [
                ['labels' => ['gpu', 'self-hosted'], 'count' => 3],
                ['labels' => ['arm64', 'self-hosted'], 'count' => 1],
            ])
            ->assertSee('3 jobs')
            ->assertSee('Create pool for these labels')
            ->assertSee('labels=gpu%2Cself-hosted', false);
    }

    public function test_no_callout_without_unmatched_jobs(): void
    {
        Livewire::actingAs($this->admin)->test(PoolsIndex::class)->assertDontSee('no matching pool');
    }

    public function test_the_pool_form_is_prefilled_with_the_requested_labels(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['labels' => 'gpu,self-hosted'])->test(Form::class)
            ->assertSet('labels', 'gpu, self-hosted');
    }

    public function test_pool_meters_follow_runner_state_changes(): void
    {
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'max_runners' => 4]);
        $runner = Runner::factory()->create(['pool_id' => $pool->id, 'state' => RunnerState::Idle]);

        $page = Livewire::actingAs($this->admin)->test(PoolsIndex::class)->assertSee('1 idle')->assertSee('0 busy');

        $runner->forceFill(['state' => RunnerState::Busy])->save();
        $page->call('refreshRunners')->assertSee('0 idle')->assertSee('1 busy');
    }

    public function test_hosts_show_their_runners(): void
    {
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'name' => 'linux-pool']);
        $host = Host::factory()->create(['name' => 'build-01']);
        Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $host->id, 'state' => RunnerState::Busy, 'name' => 'jogger-busy-1']);
        Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $host->id, 'state' => RunnerState::Idle, 'name' => 'jogger-idle-1']);
        Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $host->id, 'state' => RunnerState::Gone, 'name' => 'jogger-gone-1']);

        Livewire::actingAs($this->admin)->test(HostsIndex::class)
            ->assertSee('1 busy')->assertSee('1 idle')
            ->assertDontSee('jogger-busy-1')
            ->call('select', $host->id)
            ->assertSee('jogger-busy-1')->assertSee('jogger-idle-1')->assertDontSee('jogger-gone-1');
    }
}
