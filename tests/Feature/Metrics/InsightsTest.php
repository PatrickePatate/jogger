<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Metrics\Actions\RollupMetrics;
use App\Domain\Metrics\Models\MetricSample;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Insights\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private Repository $repo;

    private RunnerPool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-11-10 12:30:30');
        $installation = Installation::factory()->create();
        $this->repo = Repository::factory()->create(['installation_id' => $installation->id, 'full_name' => 'acme/api']);
        $this->pool = RunnerPool::factory()->create(['installation_id' => $installation->id, 'name' => 'linux-pool']);
    }

    /** A job that waited `$wait` seconds, started in the 12:10 minute and ran two minutes. */
    private function job(int $wait, JobConclusion $conclusion = JobConclusion::Success, ?Repository $repo = null): WorkflowJob
    {
        $started = Carbon::parse('2026-11-10 12:10:20');

        return WorkflowJob::factory()->create([
            'repository_id' => ($repo ?? $this->repo)->id, 'pool_id' => $this->pool->id,
            'status' => JobStatus::Completed, 'conclusion' => $conclusion,
            'queued_at' => $started->copy()->subSeconds($wait), 'started_at' => $started, 'completed_at' => $started->copy()->addMinutes(2),
        ]);
    }

    public function test_rollup_yields_exact_wait_percentiles_and_counts(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $wait) {
            $this->job($wait, $wait === 10 ? JobConclusion::Failure : JobConclusion::Success);
        }
        WorkflowJob::factory()->create(['repository_id' => $this->repo->id, 'status' => JobStatus::Queued, 'queued_at' => '2026-11-10 12:10:05']);

        $rollup = app(RollupMetrics::class);
        $rollup(Carbon::parse('2026-11-10 12:10:00'), MetricSample::MINUTE);
        $rollup(Carbon::parse('2026-11-10 12:12:00'), MetricSample::MINUTE);
        $rollup(Carbon::parse('2026-11-10 12:10:00'), MetricSample::MINUTE); // idempotent

        $start = MetricSample::query()->where(['resolution' => 'minute', 'subject_type' => 'global', 'bucket' => '2026-11-10 12:10:00'])->firstOrFail();
        $this->assertSame(10, $start->jobs_started);
        $this->assertSame(5500, $start->queue_wait_p50_ms);   // median of 1..10 s
        $this->assertSame(9550, $start->queue_wait_p95_ms);   // 95th percentile, interpolated
        $this->assertSame(1, $start->queue_depth);            // the one still queued at 12:11
        $this->assertSame(0, $start->jobs_succeeded);

        $end = MetricSample::query()->where(['resolution' => 'minute', 'subject_type' => 'global', 'bucket' => '2026-11-10 12:12:00'])->firstOrFail();
        $this->assertSame(9, $end->jobs_succeeded);
        $this->assertSame(1, $end->jobs_failed);
        $this->assertSame(1200, $end->busy_seconds);

        $this->assertSame(1, MetricSample::query()->where(['resolution' => 'minute', 'subject_type' => 'pool', 'subject_id' => $this->pool->id, 'bucket' => '2026-11-10 12:10:00'])->count());
        $this->assertSame(10, MetricSample::query()->where(['resolution' => 'minute', 'subject_type' => 'repo', 'subject_id' => $this->repo->id, 'bucket' => '2026-11-10 12:10:00'])->value('jobs_started'));
    }

    public function test_the_current_minute_snapshots_runners_and_the_hour_averages_them(): void
    {
        Runner::factory()->count(2)->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Busy]);
        Runner::factory()->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Idle]);

        $this->artisan('jogger:rollup-metrics')->assertSuccessful();

        $minute = MetricSample::query()->where(['resolution' => 'minute', 'subject_type' => 'global', 'bucket' => '2026-11-10 12:30:00'])->firstOrFail();
        $this->assertSame([2, 1], [$minute->runners_busy, $minute->runners_idle]);
        $hour = MetricSample::query()->where(['resolution' => 'hour', 'subject_type' => 'pool', 'subject_id' => $this->pool->id])->firstOrFail();
        $this->assertSame([2, 1], [$hour->runners_busy, $hour->runners_idle]);
    }

    public function test_the_page_shows_totals_and_the_export_matches_the_table(): void
    {
        $this->job(4);
        $this->job(6, JobConclusion::Failure);
        app(RollupMetrics::class)(now(), MetricSample::HOUR);

        $page = Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)
            ->assertViewHas('totals', fn (array $t) => $t['started'] === 2 && $t['success_rate'] === 50 && $t['minutes'] === 4 && $t['p50'] === '5s')
            ->assertViewHas('repositories', [['name' => 'acme/api', 'started' => 2, 'succeeded' => 1, 'failed' => 1, 'success_rate' => 50, 'minutes' => 4]])
            ->assertSee('acme/api')->assertSee('linux-pool')
            ->assertSee('role="img"', false);

        $csv = $page->call('export')->assertFileDownloaded('jogger-insights-24h.csv')->effects['download']['content'];
        $this->assertStringContainsString('acme/api,2,1,1,50,4', base64_decode($csv));

        $page->set('range', '1h')->assertViewHas('totals', fn (array $t) => $t['started'] === 0);
    }

    public function test_members_only_see_their_repositories(): void
    {
        $hidden = Repository::factory()->create(['full_name' => 'secret/repo']);
        $this->job(4);
        $this->job(4, JobConclusion::Success, $hidden);
        $this->job(4, JobConclusion::Success, $hidden);
        app(RollupMetrics::class)(now(), MetricSample::HOUR);

        $member = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($member, Role::Viewer);
        $team->grant($this->repo->installation);

        $page = Livewire::actingAs($member)->test(Index::class)
            ->assertViewHas('totals', fn (array $t) => $t['started'] === 1)
            ->assertSee('acme/api')->assertDontSee('secret/repo')->assertDontSee('Host capacity');

        $this->assertStringNotContainsString('secret/repo', base64_decode($page->call('export')->effects['download']['content']));
    }
}
