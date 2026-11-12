<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Jobs\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class JobsIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Repository $api;

    private Repository $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->api = Repository::factory()->create(['full_name' => 'acme/api']);
        $this->site = Repository::factory()->create(['full_name' => 'acme/site']);
    }

    public function test_a_status_change_shows_up_on_the_next_event_without_reload(): void
    {
        $job = WorkflowJob::factory()->create(['repository_id' => $this->api->id, 'job_name' => 'unit-tests']);

        $page = Livewire::actingAs($this->admin)->test(Index::class)->assertSee('unit-tests')->assertSee('Queued');

        $job->update(['status' => 'in_progress', 'started_at' => now()]);
        $page->dispatch('echo-private:jobs,.job.status-changed', ['id' => $job->id])->assertSee('Running')->assertDontSee('Queued for —');
    }

    public function test_admins_listen_on_the_global_channel_and_others_poll(): void
    {
        $this->assertArrayHasKey('echo-private:jobs,.job.status-changed', Livewire::actingAs($this->admin)->test(Index::class)->instance()->getListeners());

        $viewer = User::factory()->create();
        $page = Livewire::actingAs($viewer)->test(Index::class);
        $this->assertSame([], $page->instance()->getListeners());
        $page->assertSeeHtml('wire:poll.5s');
    }

    public function test_filters_combine_and_live_in_the_query_string(): void
    {
        $pool = RunnerPool::factory()->create();
        $host = Host::factory()->create();
        $runner = Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $host->id]);
        WorkflowJob::factory()->completed()->create(['repository_id' => $this->api->id, 'job_name' => 'api-green', 'workflow_name' => 'CI', 'pool_id' => $pool->id, 'runner_id' => $runner->id]);
        WorkflowJob::factory()->completed(JobConclusion::Failure)->create(['repository_id' => $this->api->id, 'job_name' => 'api-red', 'workflow_name' => 'Deploy']);
        WorkflowJob::factory()->completed()->create(['repository_id' => $this->site->id, 'job_name' => 'site-green', 'workflow_name' => 'CI']);
        WorkflowJob::factory()->create(['repository_id' => $this->site->id, 'job_name' => 'site-lost', 'unmatched_reason' => 'NO_MATCHING_POOL: no pool has all of these labels: gpu']);
        WorkflowJob::factory()->completed()->create(['repository_id' => $this->api->id, 'job_name' => 'api-ancient', 'created_at' => now()->subDays(60)]);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('repository', $this->api->id)->assertSee('api-green')->assertSee('api-red')->assertDontSee('site-green')
            ->set('status', 'success')->assertSee('api-green')->assertDontSee('api-red')
            ->set('range', '7d')->assertDontSee('api-ancient')
            ->set('workflow', 'ci')->assertSee('api-green')
            ->set('pool', $pool->id)->set('host', $host->id)->assertSee('api-green')
            ->set('workflow', 'deploy')->assertSee('No jobs match these filters')
            ->call('clearFilters')->assertSee('site-green')->assertSee('api-ancient');

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('status', 'unmatched')->assertSee('site-lost')->assertSee('No matching pool')->assertSee('no pool has all of these labels: gpu')->assertDontSee('api-green')
            ->set('status', 'failure')->assertSee('api-red')->assertDontSee('site-lost');

        Livewire::actingAs($this->admin)->withQueryParams(['repository' => $this->site->id, 'status' => 'success'])->test(Index::class)
            ->assertSet('repository', $this->site->id)->assertSee('site-green')->assertDontSee('api-green')->assertDontSee('site-lost');
    }

    public function test_only_visible_jobs_are_listed(): void
    {
        $installation = Installation::factory()->create();
        $granted = Repository::factory()->create(['installation_id' => $installation->id, 'full_name' => 'mine/app']);
        WorkflowJob::factory()->create(['repository_id' => $granted->id, 'job_name' => 'my-job']);
        WorkflowJob::factory()->create(['repository_id' => $this->api->id, 'job_name' => 'their-job']);
        $viewer = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($viewer, Role::Viewer);
        $team->grant($installation);

        Livewire::actingAs($viewer)->test(Index::class)->assertSee('my-job')->assertDontSee('their-job')->assertDontSee('acme/api');
        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertSee('No jobs yet');
    }

    public function test_pagination_by_cursor(): void
    {
        WorkflowJob::factory()->count(30)->create(['repository_id' => $this->api->id]);
        $newest = WorkflowJob::factory()->create(['repository_id' => $this->api->id, 'job_name' => 'the-newest']);
        $oldest = WorkflowJob::query()->orderBy('id')->firstOrFail();
        $oldest->update(['job_name' => 'the-oldest']);

        Livewire::actingAs($this->admin)->test(Index::class)->assertSee('the-newest')->assertDontSee('the-oldest');
        $this->assertNotNull($newest);
    }

    public function test_first_page_of_ten_thousand_jobs_is_fast(): void
    {
        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 0; $i < 10_000; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'github_job_id' => 5_000_000 + $i, 'github_run_id' => 1, 'repository_id' => $this->api->id,
                'job_name' => "job-{$i}", 'status' => 'completed', 'conclusion' => 'success', 'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('workflow_jobs')->insert($chunk);
        }

        $started = microtime(true);
        Livewire::actingAs($this->admin)->test(Index::class)->assertOk();
        $elapsed = (microtime(true) - $started) * 1000;

        $this->assertLessThan(500, $elapsed, "first page took {$elapsed} ms");
    }

    public function test_jobs_on_github_hosted_runners_are_hidden_unless_asked_for(): void
    {
        $repository = Repository::factory()->create(['installation_id' => Installation::factory()->create()->id]);
        WorkflowJob::factory()->create(['repository_id' => $repository->id, 'job_name' => 'ours-job']);
        WorkflowJob::factory()->create(['repository_id' => $repository->id, 'job_name' => 'hosted-job', 'labels' => ['ubuntu-latest']]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('ours-job')->assertDontSee('hosted-job')
            ->set('hosted', true)
            ->assertSee('hosted-job')->assertSee('Runs on GitHub, not on Jogger');
    }
}
