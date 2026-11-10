<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Models\User;
use App\Support\Health\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_answers_without_authentication(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_is_for_administrators(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/health')->assertForbidden();
    }

    public function test_health_reports_each_dependency(): void
    {
        Host::factory()->create(['status' => HostStatus::Online]);

        $report = $this->actingAs(User::factory()->admin()->create())->getJson('/health')
            ->assertJsonStructure(['status', 'checks' => ['database', 'redis', 'reverb', 'queue', 'github', 'hosts']])->json();

        $this->assertTrue($report['checks']['database']['ok']);
        $this->assertSame('1 of 1 online', $report['checks']['hosts']['detail']);
    }

    public function test_health_is_degraded_when_redis_is_down(): void
    {
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

        $report = app(HealthCheck::class)->report();

        $this->assertSame('degraded', $report['status']);
        $this->assertFalse($report['checks']['redis']['ok']);
        $this->assertStringContainsString('Connection refused', $report['checks']['redis']['detail']);
        $this->assertTrue($report['checks']['database']['ok']);
    }

    public function test_health_is_degraded_when_every_host_is_offline(): void
    {
        Host::factory()->create(['status' => HostStatus::Offline]);

        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/health')->assertStatus(503);

        $this->assertSame('degraded', $response->json('status'));
        $this->assertFalse($response->json('checks.hosts.ok'));
    }
}
