<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Domain\Access\Models\AuditLog;
use App\Domain\Cloud\Actions\ProvisionCloudHost;
use App\Domain\Cloud\Actions\ReconcileCloudHosts;
use App\Domain\Cloud\Actions\ScaleDownCloudHosts;
use App\Domain\Cloud\Contracts\CloudDriver;
use App\Domain\Cloud\Data\CloudServer;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Cloud\Testing\FakeCloudDriver;
use App\Domain\Github\Models\Installation;
use App\Domain\Hosts\Actions\PickHost;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Actions\TransitionRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScaleDownTest extends TestCase
{
    use RefreshDatabase;

    private FakeCloudDriver $cloud;

    private CloudProvider $provider;

    private RunnerPool $pool;

    private CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();

        // Only broadcasts are faked: the actions run for real against the in-memory provider.
        Queue::fake([BroadcastEvent::class]);
        config(['jogger.cloud.max_hosts' => 10]);

        $this->start = CarbonImmutable::parse('2026-11-12 10:00:00');
        $this->travelTo($this->start);

        $this->cloud = app(FakeCloudDriver::class);
        $this->provider = CloudProvider::factory()->create(['driver' => 'fake']);
        $this->pool = RunnerPool::factory()->create([
            'installation_id' => Installation::factory()->create(['account_login' => 'acme'])->id,
            'name' => 'Linux x64', 'max_runners' => 50, 'image' => 'ghcr.io/acme/runner:1',
        ]);
    }

    public function test_an_idle_host_is_deleted_only_once_it_has_been_idle_longer_than_idle_seconds(): void
    {
        $template = $this->template(['idle_seconds' => 300]);
        $host = $this->onlineHost($template, ['cloud_state_changed_at' => $this->start]);

        $this->travelTo($this->start->addSeconds(300));
        $this->assertSame(0, app(ScaleDownCloudHosts::class)());
        $this->assertSame(CloudHostState::Online, $host->fresh()->cloud_state);

        $this->travelTo($this->start->addSeconds(301));
        $this->assertSame(1, app(ScaleDownCloudHosts::class)());

        $row = Host::withTrashed()->findOrFail($host->id);
        $this->assertSame(CloudHostState::Deleted, $row->cloud_state);
        $this->assertSame(HostStatus::Revoked, $row->status);
        $this->assertNull($row->credential_hash);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame([$host->provider_server_id], $this->cloud->deleted);
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.host_deleted')->where('target_id', $host->id)->exists());
    }

    public function test_a_host_with_a_running_runner_is_kept(): void
    {
        $host = $this->onlineHost($this->template(), ['cloud_state_changed_at' => $this->start]);
        $this->runner($host, RunnerState::Busy);

        $this->travelTo($this->start->addHours(3));
        $this->assertSame(0, app(ScaleDownCloudHosts::class)());

        $this->assertSame(CloudHostState::Online, $host->fresh()->cloud_state);
        $this->assertSame([], $this->cloud->deleted);
    }

    public function test_the_idle_clock_starts_when_the_last_runner_ended(): void
    {
        $host = $this->onlineHost($this->template(), ['cloud_state_changed_at' => $this->start]);
        $ended = $this->start->addHours(2);
        $this->runner($host, RunnerState::Gone, ['finished_at' => $ended, 'state_changed_at' => $ended]);

        $this->travelTo($ended->addSeconds(300));
        $this->assertSame(0, app(ScaleDownCloudHosts::class)());

        $this->travelTo($ended->addSeconds(301));
        $this->assertSame(1, app(ScaleDownCloudHosts::class)());
    }

    public function test_warm_hosts_are_kept_and_the_oldest_idle_host_goes_first(): void
    {
        $template = $this->template(['warm_hosts' => 2, 'max_hosts' => 5]);
        $oldest = $this->onlineHost($template, ['billed_from' => $this->start->subHours(3), 'cloud_state_changed_at' => $this->start->subHours(3)]);
        $this->onlineHost($template, ['billed_from' => $this->start->subHours(2), 'cloud_state_changed_at' => $this->start->subHours(2)]);
        $this->onlineHost($template, ['billed_from' => $this->start->subHour(), 'cloud_state_changed_at' => $this->start->subHour()]);

        $this->travelTo($this->start->addHour());
        $this->assertSame(1, app(ScaleDownCloudHosts::class)());

        $this->assertSame([$oldest->provider_server_id], $this->cloud->deleted);
        $this->assertSame(2, Host::query()->where('host_template_id', $template->id)->where('cloud_state', CloudHostState::Online->value)->count());
        $this->assertSame(0, app(ScaleDownCloudHosts::class)());
    }

    public function test_hourly_billing_keeps_an_idle_host_until_the_last_five_minutes_of_its_paid_hour(): void
    {
        $template = $this->template(['hourly_billing' => true, 'idle_seconds' => 300]);
        $host = $this->onlineHost($template, ['billed_from' => $this->start, 'cloud_state_changed_at' => $this->start]);

        $this->travelTo($this->start->addMinutes(20));
        $this->assertSame(0, app(ScaleDownCloudHosts::class)());

        $this->travelTo($this->start->addMinutes(54));
        $this->assertSame(0, app(ScaleDownCloudHosts::class)());

        $this->travelTo($this->start->addMinutes(56));
        $this->assertSame(1, app(ScaleDownCloudHosts::class)());
        $this->assertSame([$host->provider_server_id], $this->cloud->deleted);
    }

    public function test_a_host_past_its_max_lifetime_drains_and_is_deleted_once_empty(): void
    {
        // Hourly billing and a fresh paid hour would both keep an idle host; the lifetime rule ignores them.
        $template = $this->template(['hourly_billing' => true, 'max_runners_per_host' => 2, 'max_lifetime_seconds' => 3600]);
        $host = $this->onlineHost($template, ['billed_from' => $this->start->subHours(2), 'cloud_state_changed_at' => $this->start->subHours(2)]);
        $busy = $this->runner($host, RunnerState::Busy);
        $pick = app(PickHost::class);
        $this->assertSame($host->id, $pick($this->pool)?->id);

        $this->assertSame(0, app(ScaleDownCloudHosts::class)());
        $this->assertSame(CloudHostState::Draining, $host->fresh()->cloud_state);
        $this->assertSame([], $this->cloud->deleted);
        $this->assertNull($pick($this->pool), 'a draining host takes no new runners');

        app(TransitionRunner::class)($busy, RunnerState::Terminating);
        app(TransitionRunner::class)($busy, RunnerState::Gone);

        $this->assertSame(1, app(ScaleDownCloudHosts::class)());
        $this->assertSame([$host->provider_server_id], $this->cloud->deleted);
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.host_draining')->where('target_id', $host->id)->exists());
    }

    public function test_a_failed_delete_leaves_the_host_failed_and_reconciliation_retries_it(): void
    {
        $host = $this->onlineHost($this->template(), ['cloud_state_changed_at' => $this->start]);
        $serverId = $host->provider_server_id;
        $this->cloud->failNextDelete(CloudException::api('provider unavailable', 503));

        $this->travelTo($this->start->addMinutes(10));
        $this->assertSame(0, app(ScaleDownCloudHosts::class)());
        $this->assertSame(CloudHostState::Failed, $host->fresh()->cloud_state);
        $this->assertSame([], $this->cloud->deleted);
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.host_delete_failed')->where('target_id', $host->id)->exists());

        $this->assertSame(['orphan_servers' => 0, 'lost_hosts' => 0, 'retried' => 1], app(ReconcileCloudHosts::class)());
        $this->assertSame(CloudHostState::Deleted, Host::withTrashed()->findOrFail($host->id)->cloud_state);
        $this->assertSame([$serverId], $this->cloud->deleted);
    }

    public function test_a_managed_server_that_no_host_claims_is_deleted_only_after_ten_minutes(): void
    {
        $claimed = $this->onlineHost($this->template(), ['cloud_state_changed_at' => $this->start]);
        $old = $this->server();

        $this->travelTo($this->start->addMinutes(9));
        $young = $this->server();

        $this->travelTo($this->start->addMinutes(11));
        $this->assertSame(['orphan_servers' => 1, 'lost_hosts' => 0, 'retried' => 0], app(ReconcileCloudHosts::class)());

        $this->assertSame([$old->id], $this->cloud->deleted);
        $this->assertEqualsCanonicalizing([$claimed->provider_server_id, $young->id], array_map(fn (CloudServer $s) => $s->id, $this->cloud->servers()));
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.orphan_server_deleted')->exists());
    }

    public function test_a_host_whose_server_is_gone_is_retired_as_lost_without_a_provider_call(): void
    {
        $template = $this->template();
        $online = $this->onlineHost($template, ['provider_server_id' => 'gone-1']);
        $draining = $this->onlineHost($template, ['provider_server_id' => 'gone-2', 'cloud_state' => CloudHostState::Draining]);

        $this->assertSame(['orphan_servers' => 0, 'lost_hosts' => 2, 'retried' => 0], app(ReconcileCloudHosts::class)());

        $this->assertSame([], $this->cloud->deleted);
        foreach ([$online, $draining] as $host) {
            $row = Host::withTrashed()->findOrFail($host->id);
            $this->assertSame(CloudHostState::Deleted, $row->cloud_state);
            $this->assertSame(HostStatus::Revoked, $row->status);
            $this->assertNull($row->credential_hash);
            $this->assertNotNull($row->deleted_at);
        }
        $this->assertSame(2, AuditLog::query()->where('action', 'cloud.host_lost')->count());
    }

    public function test_a_failed_listing_skips_the_provider_and_deletes_nothing(): void
    {
        $host = $this->onlineHost($this->template(), ['provider_server_id' => 'gone-1']);
        $this->server();
        $this->travelTo($this->start->addMinutes(11));
        $this->cloud->failNextList(CloudException::api('unavailable', 503));

        $this->assertSame(['orphan_servers' => 0, 'lost_hosts' => 0, 'retried' => 0], app(ReconcileCloudHosts::class)());

        $this->assertSame([], $this->cloud->deleted);
        $this->assertSame(CloudHostState::Online, $host->fresh()->cloud_state);
        $this->assertSame(1, AuditLog::query()->where('action', 'cloud.reconcile_failed')->count());
    }

    public function test_a_host_deleted_for_idleness_is_not_recreated_when_demand_is_zero(): void
    {
        $this->onlineHost($this->template(['idle_seconds' => 300, 'max_hosts' => 5]), ['cloud_state_changed_at' => $this->start]);
        $this->cloud->created = [];
        $this->travelTo($this->start->addMinutes(10));

        $this->artisan('jogger:scale-cloud-hosts')->expectsOutputToContain('1 idle cloud host(s) deleted')->assertSuccessful();
        $this->artisan('jogger:scale-cloud-hosts')->expectsOutputToContain('Requested 0 cloud host(s)')->assertSuccessful();

        $this->assertSame([], $this->cloud->created);
        $this->assertSame(0, Host::query()->whereIn('cloud_state', CloudHostState::open())->count());
    }

    public function test_a_host_of_a_disabled_template_is_deleted_once_idle_whatever_its_warm_count(): void
    {
        $template = $this->template(['status' => 'disabled', 'warm_hosts' => 2, 'hourly_billing' => true]);
        $host = $this->onlineHost($template, ['cloud_state_changed_at' => $this->start]);

        $this->travelTo($this->start->addMinutes(10));
        $this->assertSame(1, app(ScaleDownCloudHosts::class)());
        $this->assertSame([$host->provider_server_id], $this->cloud->deleted);
    }

    /** @param  array<string, mixed>  $attributes */
    private function template(array $attributes = []): HostTemplate
    {
        $template = HostTemplate::factory()->create(array_merge([
            'cloud_provider_id' => $this->provider->id,
            'idle_seconds' => 300,
            'hourly_billing' => false,
            'max_hosts' => 5,
        ], $attributes));
        $template->pools()->attach($this->pool);

        return $template;
    }

    /** A server at the provider, labelled as a managed server of this instance, created now. */
    private function server(): CloudServer
    {
        return $this->cloud->create($this->provider, new ServerSpec(
            name: 'cloud-'.Str::lower(Str::random(8)),
            serverType: 'cx22',
            location: 'fsn1',
            image: 'ubuntu-24.04',
            userData: '#cloud-config',
            ipv4: true,
            labels: [
                CloudDriver::MANAGED_BY_LABEL => 'jogger',
                CloudDriver::INSTANCE_LABEL => ProvisionCloudHost::instanceId(),
            ],
            options: [],
        ));
    }

    /**
     * An online cloud host. Its server is created now unless the attributes name a provider_server_id, which then
     * stands for a server the provider does not have.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function onlineHost(HostTemplate $template, array $attributes = []): Host
    {
        $serverId = $attributes['provider_server_id'] ?? $this->server()->id;

        return Host::factory()->create(array_merge([
            'host_template_id' => $template->id,
            'cloud_state' => CloudHostState::Online,
            'ephemeral' => true,
            'labels' => $template->labels,
            'billed_from' => $this->start,
            'cloud_state_changed_at' => $this->start,
        ], $attributes, ['provider_server_id' => $serverId]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function runner(Host $host, RunnerState $state, array $attributes = []): Runner
    {
        return Runner::factory()->create(array_merge(['pool_id' => $this->pool->id, 'host_id' => $host->id, 'state' => $state], $attributes));
    }
}
