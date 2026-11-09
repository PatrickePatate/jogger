<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Handlers\HandleWorkflowJob;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Events\JobStatusChanged;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MirrorTest extends TestCase
{
    use RefreshDatabase;

    private Repository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // broadcasts are queued; there is no Reverb server in tests
        $this->repo = Repository::factory()->create(['github_repo_id' => 186853002]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $stage): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/github/workflow_job.{$stage}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private function deliver(string $stage): void
    {
        $delivery = WebhookDelivery::factory()->create(['event' => 'workflow_job', 'payload' => $this->payload($stage)]);
        app(HandleWorkflowJob::class)->handle($delivery);
    }

    public function test_a_job_advances_through_its_lifecycle_in_one_row(): void
    {
        $this->deliver('queued');
        $job = WorkflowJob::query()->sole();
        $this->assertSame(JobStatus::Queued, $job->status);
        $this->assertNull($job->started_at);
        $this->assertNotNull($job->queued_at);
        $this->assertSame(['self-hosted', 'linux', 'x64', 'jogger-large'], $job->labels);
        $this->assertSame($this->repo->id, $job->repository_id);

        $this->deliver('in_progress');
        $job = WorkflowJob::query()->sole();
        $this->assertSame(JobStatus::InProgress, $job->status);
        $this->assertNotNull($job->started_at);
        $this->assertNull($job->completed_at);

        $this->deliver('completed');
        $job = WorkflowJob::query()->sole();
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(JobConclusion::Success, $job->conclusion);
        $this->assertNotNull($job->completed_at);
        $this->assertNotNull($job->started_at);
        $this->assertTrue($job->completed_at->gte($job->started_at));
    }

    public function test_completed_before_in_progress_still_ends_completed(): void
    {
        $this->deliver('queued');
        $this->deliver('completed');
        $this->deliver('in_progress');

        $job = WorkflowJob::query()->sole();
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(JobConclusion::Success, $job->conclusion);
    }

    public function test_a_job_first_seen_as_completed_is_created_completed(): void
    {
        $this->deliver('completed');
        $this->deliver('queued');

        $this->assertSame(JobStatus::Completed, WorkflowJob::query()->sole()->status);
    }

    public function test_redelivery_is_idempotent(): void
    {
        $this->deliver('in_progress');
        $this->deliver('in_progress');

        $this->assertSame(1, WorkflowJob::query()->count());
    }

    public function test_one_event_is_broadcast_per_real_transition(): void
    {
        Event::fake([JobStatusChanged::class]);

        $this->deliver('queued');
        $this->deliver('queued');
        $this->deliver('in_progress');
        $this->deliver('in_progress');
        $this->deliver('completed');
        $this->deliver('queued');
        $this->deliver('completed');

        Event::assertDispatchedTimes(JobStatusChanged::class, 3);
    }

    public function test_stale_events_do_not_broadcast(): void
    {
        $this->deliver('completed');
        Event::fake([JobStatusChanged::class]);

        $this->deliver('in_progress');

        Event::assertNotDispatched(JobStatusChanged::class);
    }

    public function test_jobs_of_unknown_repositories_are_ignored_and_logged(): void
    {
        $this->repo->delete();
        Log::spy();

        $this->deliver('queued');

        $this->assertSame(0, WorkflowJob::query()->count());
        Log::shouldHaveReceived('info')->once();
    }

    public function test_the_event_broadcasts_on_global_repository_and_job_channels(): void
    {
        $job = WorkflowJob::factory()->create(['repository_id' => $this->repo->id]);

        $names = array_map(fn ($c) => $c->name, (new JobStatusChanged($job))->broadcastOn());

        $this->assertSame(['private-jobs', "private-repository.{$this->repo->id}", "private-job.{$job->id}"], $names);
        $this->assertSame('queued', (new JobStatusChanged($job))->broadcastWith()['status']);
    }

    public function test_visibility_follows_team_grants(): void
    {
        $job = WorkflowJob::factory()->create(['repository_id' => $this->repo->id]);
        WorkflowJob::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($member, Role::Viewer);
        $team->grant(Installation::query()->findOrFail($this->repo->installation_id));

        $this->assertEquals([$job->id], WorkflowJob::query()->visibleTo($member)->pluck('id')->all());
        $this->assertSame(0, WorkflowJob::query()->visibleTo($outsider)->count());
        $this->assertSame(2, WorkflowJob::query()->visibleTo(User::factory()->admin()->create())->count());
    }

    public function test_channel_authorization(): void
    {
        $job = WorkflowJob::factory()->create(['repository_id' => $this->repo->id]);
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create();
        $team->addMember($member, Role::Viewer);
        $team->grant(Installation::query()->findOrFail($this->repo->installation_id));
        $outsider = User::factory()->create();

        $auth = fn (User $u, string $channel) => $this->actingAs($u)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => "private-{$channel}",
        ])->status();

        $this->assertSame(200, $auth($admin, 'jobs'));
        $this->assertSame(403, $auth($member, 'jobs'));
        $this->assertSame(200, $auth($member, "repository.{$this->repo->id}"));
        $this->assertSame(403, $auth($outsider, "repository.{$this->repo->id}"));
        $this->assertSame(200, $auth($member, "job.{$job->id}"));
        $this->assertSame(403, $auth($outsider, "job.{$job->id}"));
    }
}
