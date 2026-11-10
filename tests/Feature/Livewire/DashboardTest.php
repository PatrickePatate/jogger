<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Overview\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function seedActivity(): void
    {
        $installation = Installation::factory()->create();
        $repo = Repository::factory()->create(['installation_id' => $installation->id]);
        $pool = RunnerPool::factory()->create(['installation_id' => $installation->id, 'name' => 'linux-pool', 'max_runners' => 10]);
        $host = Host::factory()->create(['name' => 'build-01', 'status' => HostStatus::Online]);

        WorkflowJob::factory()->count(3)->create(['repository_id' => $repo->id, 'status' => JobStatus::Queued]);
        WorkflowJob::factory()->count(2)->create(['repository_id' => $repo->id, 'status' => JobStatus::InProgress]);
        WorkflowJob::factory()->count(3)->completed(JobConclusion::Success)->create(['repository_id' => $repo->id, 'completed_at' => now()->subHour()]);
        WorkflowJob::factory()->completed(JobConclusion::Failure)->create(['repository_id' => $repo->id, 'completed_at' => now()->subHour(), 'job_name' => 'broken-build']);
        WorkflowJob::factory()->completed(JobConclusion::Failure)->create(['repository_id' => $repo->id, 'completed_at' => now()->subDays(3)]);

        foreach ([RunnerState::Idle, RunnerState::Idle, RunnerState::Busy, RunnerState::Provisioning, RunnerState::Gone] as $state) {
            Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $host->id, 'state' => $state]);
        }
    }

    public function test_tiles_reflect_the_seeded_data(): void
    {
        $this->seedActivity();

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertViewHas('tiles', [
                'queued' => 3, 'running' => 2, 'idle' => 2, 'busy' => 1, 'starting' => 1, 'finished' => 4, 'success_rate' => 75,
            ])
            ->assertViewHas('pools', fn (array $pools) => count($pools) === 1 && $pools[0]['busy'] === 1 && $pools[0]['idle'] === 2 && $pools[0]['max'] === 10)
            ->assertSee('2 idle · 1 busy')
            ->assertSee('75%')
            ->assertSee('linux-pool')
            ->assertSee('build-01')
            ->assertSee('broken-build');
    }

    public function test_checklist_follows_real_state_and_hides_when_complete(): void
    {
        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSee('Getting started')
            ->assertSee('1 of 5');

        GithubApp::factory()->create();
        $this->seedActivity();

        Livewire::actingAs($this->admin)->test(Dashboard::class)->assertDontSee('Getting started');
    }

    public function test_members_see_no_hosts_or_checklist(): void
    {
        $this->seedActivity();

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->assertDontSee('Getting started')
            ->assertDontSee('build-01')
            ->assertDontSee('linux-pool')
            ->assertViewHas('tiles', fn (array $tiles) => $tiles['queued'] === 0);
    }

    public function test_a_load_stays_within_the_query_budget(): void
    {
        $this->seedActivity();
        $this->actingAs($this->admin);

        DB::enableQueryLog();
        $this->get('/')->assertOk();

        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
    }
}
