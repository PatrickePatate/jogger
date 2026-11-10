<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Actions\GetJobActivity;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_jobs_and_failures_per_day_within_the_window(): void
    {
        $repo = Repository::factory()->create();
        WorkflowJob::factory()->count(2)->create(['repository_id' => $repo->id, 'queued_at' => now()->subDays(2)]);
        WorkflowJob::factory()->completed(JobConclusion::Failure)->create(['repository_id' => $repo->id, 'queued_at' => now()->subDays(2)]);
        WorkflowJob::factory()->create(['repository_id' => $repo->id, 'queued_at' => now()->subWeeks(30)]);

        $result = app(GetJobActivity::class)(User::factory()->admin()->create());
        $day = now()->subDays(2)->toDateString();

        $this->assertSame(3, $result['days'][$day]);
        $this->assertSame(1, $result['failed'][$day]);
        $this->assertSame(3, $result['total']);
    }

    public function test_it_only_counts_what_the_user_can_see(): void
    {
        $mine = Repository::factory()->create();
        $theirs = Repository::factory()->create();
        WorkflowJob::factory()->create(['repository_id' => $mine->id]);
        WorkflowJob::factory()->count(4)->create(['repository_id' => $theirs->id]);
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, Role::Viewer);
        $team->grant(Installation::query()->findOrFail($mine->installation_id));

        $this->assertSame(1, app(GetJobActivity::class)($user)['total']);
    }

    public function test_the_heatmap_shades_days_by_volume_and_hides_the_future(): void
    {
        $html = Blade::render('<x-ui.activity-heatmap :days="$days" :weeks="4" />', ['days' => [
            now()->toDateString() => 10,
            now()->subDay()->toDateString() => 1,
        ]]);

        $this->assertStringContainsString('data-date="'.now()->toDateString().'" data-count="10"', $html);
        $this->assertStringContainsString('bg-info"', $html);
        $this->assertStringContainsString('10 jobs on', $html);
        $this->assertStringContainsString('1 job on', $html);
        $this->assertStringContainsString('No jobs on', $html);
        $this->assertSame(0, preg_match('/data-date="'.now()->addDay()->toDateString().'"/', $html));
    }

    public function test_the_overview_shows_the_total_for_a_signed_in_user(): void
    {
        $repo = Repository::factory()->create();
        WorkflowJob::factory()->count(3)->create(['repository_id' => $repo->id]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/')->assertOk()->assertSee('CI activity')->assertSee('data-testid="activity-total">3<', false);
    }
}
