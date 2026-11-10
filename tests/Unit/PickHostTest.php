<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Hosts\Actions\PickHost;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickHostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $over
     */
    private function host(string $name, array $over = []): Host
    {
        return Host::factory()->create($over + ['name' => $name, 'cpu_used' => 0, 'memory_used_mb' => 0]);
    }

    public function test_skips_hosts_that_are_not_online(): void
    {
        $pool = RunnerPool::factory()->create();
        foreach ([HostStatus::Offline, HostStatus::Cordoned, HostStatus::Draining, HostStatus::Pending, HostStatus::Revoked] as $status) {
            $this->host("h-{$status->value}", ['status' => $status]);
        }
        $this->assertNull(app(PickHost::class)($pool));

        $online = $this->host('online');
        $this->assertSame($online->id, app(PickHost::class)($pool)?->id);
        $this->assertNull(app(PickHost::class)($pool, [$online->id]));
    }

    public function test_skips_hosts_without_capacity(): void
    {
        $pool = RunnerPool::factory()->create(['cpu_limit' => 4, 'memory_limit_mb' => 8192]);
        $this->host('small', ['cpu_total' => 2, 'memory_total_mb' => 32000]);
        $this->host('busy', ['cpu_total' => 8, 'memory_total_mb' => 16384, 'memory_used_mb' => 12000]);
        $this->assertNull(app(PickHost::class)($pool));

        // Reserved by runners already placed there, even if the host does not show the usage yet.
        $full = $this->host('full', ['cpu_total' => 8, 'memory_total_mb' => 32000]);
        Runner::factory()->count(2)->create(['pool_id' => $pool->id, 'host_id' => $full->id, 'state' => RunnerState::Provisioning]);
        $this->assertNull(app(PickHost::class)($pool));

        Runner::query()->where('host_id', $full->id)->limit(1)->update(['state' => RunnerState::Gone->value]);
        $this->assertSame($full->id, app(PickHost::class)($pool)?->id);
    }

    public function test_honors_host_labels_and_architecture_and_prefers_the_emptiest(): void
    {
        $pool = RunnerPool::factory()->create(['labels' => ['self-hosted', 'linux', 'arm64'], 'allowed_host_labels' => ['ssd']]);
        $this->host('x64-ssd', ['labels' => ['ssd']]);
        $this->host('arm-plain', ['arch' => 'arm64']);
        $this->assertNull(app(PickHost::class)($pool));

        $a = $this->host('arm-ssd-a', ['arch' => 'arm64', 'labels' => ['SSD', 'fast'], 'memory_used_mb' => 8000]);
        $this->assertSame($a->id, app(PickHost::class)($pool)?->id);
        $b = $this->host('arm-ssd-b', ['arch' => 'arm64', 'labels' => ['ssd']]);
        $this->assertSame($b->id, app(PickHost::class)($pool)?->id);
    }
}
