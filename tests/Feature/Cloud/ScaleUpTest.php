<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Domain\Access\Models\AuditLog;
use App\Domain\Cloud\Actions\ReapUnbootedCloudHosts;
use App\Domain\Cloud\Actions\ScaleCloudHosts;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Cloud\Testing\FakeCloudDriver;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Actions\EnrollHost;
use App\Domain\Hosts\Actions\PickHost;
use App\Domain\Hosts\Actions\RecordHeartbeat;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Jobs\ProvisionRunnerJob;
use App\Jobs\ScaleCloudHostsJob;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScaleUpTest extends TestCase
{
    use RefreshDatabase;

    private FakeCloudDriver $cloud;

    private CloudProvider $provider;

    private RunnerPool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        // Only broadcasts are faked: provisioning and the scaler run for real, as in ProvisionTest.
        Queue::fake([BroadcastEvent::class]);
        config(['jogger.cloud.max_hosts' => 10]);

        $this->cloud = app(FakeCloudDriver::class);
        $this->provider = CloudProvider::factory()->create(['driver' => 'fake']);
        $this->pool = RunnerPool::factory()->create([
            'installation_id' => Installation::factory()->create(['account_login' => 'acme'])->id,
            'name' => 'Linux x64', 'max_runners' => 50, 'image' => 'ghcr.io/acme/runner:1',
        ]);
    }

    /** @return iterable<string, array{int, int}> jobs waiting, servers expected (max_runners_per_host = 3) */
    public static function waitingJobs(): iterable
    {
        yield 'one job' => [1, 1];
        yield 'max_runners_per_host jobs' => [3, 1];
        yield 'one more than that' => [4, 2];
    }

    #[DataProvider('waitingJobs')]
    public function test_servers_follow_the_waiting_jobs_at_max_runners_per_host(int $jobs, int $servers): void
    {
        $this->template(['max_runners_per_host' => 3, 'max_hosts' => 5]);
        $this->jobs($jobs);

        $this->assertSame($servers, app(ScaleCloudHosts::class)());
        $this->assertCount($servers, $this->cloud->created);
    }

    public function test_an_inactive_provider_gets_no_new_servers(): void
    {
        $this->template(['max_hosts' => 5]);
        $this->jobs(2);
        $this->provider->update(['status' => 'inactive']);

        $this->assertSame(0, app(ScaleCloudHosts::class)());
        $this->assertCount(0, $this->cloud->created);

        $this->provider->update(['status' => 'active']);

        $this->assertSame(2, app(ScaleCloudHosts::class)());
    }

    public function test_no_server_when_an_online_host_has_room(): void
    {
        $template = $this->template(['max_runners_per_host' => 2]);
        $this->onlineCloudHost($template);
        $this->jobs(2);

        $this->assertSame(0, app(ScaleCloudHosts::class)());
        $this->assertSame([], $this->cloud->created);
    }

    public function test_warm_hosts_are_created_without_jobs_and_not_twice(): void
    {
        $template = $this->template(['warm_hosts' => 2, 'max_hosts' => 5]);

        $this->assertSame(2, app(ScaleCloudHosts::class)());
        $this->assertSame(0, app(ScaleCloudHosts::class)());
        $this->assertSame(2, Host::query()->where('host_template_id', $template->id)->where('cloud_state', 'booting')->count());
    }

    public function test_scale_from_zero_creates_nothing_without_jobs_or_without_pools(): void
    {
        $this->template();
        HostTemplate::factory()->create(['cloud_provider_id' => $this->provider->id, 'max_hosts' => 5, 'warm_hosts' => 1]);

        $this->assertSame(0, app(ScaleCloudHosts::class)());
        $this->assertSame([], $this->cloud->created);
    }

    public function test_the_template_cap_limits_its_hosts(): void
    {
        $this->template(['max_hosts' => 2]);
        $this->jobs(5);

        $this->assertSame(2, app(ScaleCloudHosts::class)());
        $this->assertSame(0, app(ScaleCloudHosts::class)());
        $this->assertCount(2, $this->cloud->created);
    }

    public function test_the_global_cap_limits_all_templates_together(): void
    {
        config(['jogger.cloud.max_hosts' => 3]);
        $this->template(['name' => 'alpha', 'max_hosts' => 5]);
        $this->template(['name' => 'beta', 'max_hosts' => 5]);
        $this->jobs(4);

        $this->assertSame(3, app(ScaleCloudHosts::class)());
        $this->assertCount(3, $this->cloud->created);
    }

    public function test_booting_hosts_count_as_capacity_so_a_second_run_creates_nothing(): void
    {
        $template = $this->template(['max_hosts' => 5]);
        $this->jobs(2);

        $this->assertSame(2, app(ScaleCloudHosts::class)());
        $this->assertSame(0, app(ScaleCloudHosts::class)());
        $this->assertSame(2, Host::query()->where('host_template_id', $template->id)->where('cloud_state', 'booting')->count());
    }

    public function test_a_refused_server_leaves_no_host_behind_and_is_audited(): void
    {
        $template = $this->template();
        $this->jobs(1);
        $this->cloud->failNextCreate(CloudException::quotaReached('quota exhausted'));

        $this->assertSame(0, app(ScaleCloudHosts::class)());

        $this->assertSame(0, Host::withTrashed()->where('host_template_id', $template->id)->count());
        $this->assertSame(0, EnrollmentToken::query()->count());
        $this->assertSame([], $this->cloud->created);

        $audit = AuditLog::query()->where('action', 'cloud.host_create_failed')->sole();
        $this->assertSame('quota', $audit->metadata['reason']);
        $this->assertSame($template->id, $audit->target_id);
    }

    public function test_enrolling_the_servers_token_brings_the_host_online_and_provisions_the_waiting_job(): void
    {
        FakeGithubGateway::install();
        $template = $this->template(['max_hosts' => 1]);
        $job = $this->jobs(1)[0];
        $this->assertSame(1, app(ScaleCloudHosts::class)());

        preg_match('/JOGGER_ENROLL_TOKEN=(\S+)/', $this->cloud->created[0]->userData, $matches);
        $this->assertArrayHasKey(1, $matches);

        [$host] = app(EnrollHost::class)($matches[1], 'ignored-name', [
            'arch' => 'x64', 'os' => 'linux', 'docker_version' => '27.3.1', 'cpu_total' => 4,
            'memory_total_mb' => 8192, 'disk_free_gb' => 50, 'agent_version' => '0.1.0',
        ]);

        $this->assertTrue($host->ephemeral);
        $this->assertSame($template->id, $host->host_template_id);
        $this->assertSame(CloudHostState::Online, $host->cloud_state);
        $this->assertSame($this->cloud->created[0]->name, $host->name);
        $this->assertNotNull($host->provider_server_id);
        $this->assertSame(HostStatus::Pending, $host->status);
        $this->assertSame(0, Runner::query()->count());

        // The first heartbeat makes it an ordinary online host, and the waiting job gets its runner on it.
        app(RecordHeartbeat::class)($host, ['cpu_total' => 4, 'memory_total_mb' => 8192]);

        $runner = Runner::query()->sole();
        $this->assertSame($host->id, $runner->host_id);
        $this->assertSame(RunnerState::Provisioning, $runner->state);
        $this->assertSame($this->pool->id, $runner->pool_id);
        $this->assertNull($job->fresh()->unmatched_reason);
    }

    public function test_pick_host_respects_max_runners_per_host_and_cloud_state(): void
    {
        $template = $this->template(['max_runners_per_host' => 2]);
        $host = $this->onlineCloudHost($template);
        $pick = app(PickHost::class);

        $host->update(['cloud_state' => CloudHostState::Booting]);
        $this->assertNull($pick($this->pool));

        $host->update(['cloud_state' => CloudHostState::Online]);
        Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $host->id, 'state' => RunnerState::Busy]);
        $this->assertSame($host->id, $pick($this->pool)?->id);

        Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $host->id, 'state' => RunnerState::Idle]);
        $this->assertNull($pick($this->pool));

        // A host without a template is not limited that way.
        $plain = Host::factory()->create(['name' => 'plain-host']);
        $this->assertSame($plain->id, $pick($this->pool)?->id);
    }

    public function test_a_server_that_never_boots_is_deleted_after_the_timeout(): void
    {
        $template = $this->template(['max_hosts' => 5]);
        $this->jobs(1);
        app(ScaleCloudHosts::class)();
        $stuck = Host::query()->where('cloud_state', 'booting')->sole();
        $serverId = $stuck->provider_server_id;
        $live = $this->onlineCloudHost($template);

        Host::query()->whereKey($stuck->id)->update(['cloud_state_changed_at' => now()->subSeconds((int) config('jogger.cloud.boot_timeout_seconds') + 1)]);
        Host::query()->whereKey($live->id)->update(['cloud_state_changed_at' => now()->subHour()]);

        $this->assertSame(1, app(ReapUnbootedCloudHosts::class)());

        $this->assertSame(CloudHostState::Deleted, $stuck->fresh()->cloud_state);
        $this->assertSame([$serverId], $this->cloud->deleted);
        $this->assertSame(CloudHostState::Online, $live->fresh()->cloud_state);
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.host_boot_timeout')->where('target_id', $stuck->id)->exists());
    }

    public function test_a_job_without_a_host_asks_for_a_cloud_host_only_when_its_pool_has_a_template(): void
    {
        Queue::fake([BroadcastEvent::class, ScaleCloudHostsJob::class]);
        $job = $this->jobs(1)[0];

        ProvisionRunnerJob::dispatch($this->pool->id, $job->id);
        Queue::assertNotPushed(ScaleCloudHostsJob::class);

        $this->template();
        ProvisionRunnerJob::dispatch($this->pool->id, $job->id);
        Queue::assertPushed(ScaleCloudHostsJob::class, 1);
    }

    public function test_the_scheduled_command_reaps_and_scales(): void
    {
        $this->template(['max_hosts' => 5]);
        $this->jobs(2);

        $this->artisan('jogger:scale-cloud-hosts')->expectsOutputToContain('Requested 2 cloud host(s)')->assertSuccessful();
        $this->assertCount(2, $this->cloud->created);
    }

    private function template(array $attributes = []): HostTemplate
    {
        $template = HostTemplate::factory()->create(['cloud_provider_id' => $this->provider->id] + $attributes);
        $template->pools()->attach($this->pool);

        return $template;
    }

    /** @return list<WorkflowJob> */
    private function jobs(int $count): array
    {
        return WorkflowJob::factory()->count($count)->create(['pool_id' => $this->pool->id])->all();
    }

    private function onlineCloudHost(HostTemplate $template): Host
    {
        return Host::factory()->create([
            'host_template_id' => $template->id,
            'cloud_state' => CloudHostState::Online,
            'ephemeral' => true,
            'labels' => $template->labels,
        ]);
    }
}
