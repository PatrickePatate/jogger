<?php

declare(strict_types=1);

namespace Tests\Feature\Hosts;

use App\Domain\Hosts\Actions\CordonHost;
use App\Domain\Hosts\Actions\DrainHost;
use App\Domain\Hosts\Actions\EnrollHost;
use App\Domain\Hosts\Actions\RequestAgentUpdate;
use App\Domain\Hosts\Actions\RevokeHost;
use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Enums\CommandStatus;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Runners\Contracts\RunnerCensus;
use App\Livewire\Hosts\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function host(HostStatus $status = HostStatus::Online): Host
    {
        return Host::factory()->create([
            'status' => $status, 'last_seen_at' => now(), 'credential_hash' => Host::hashCredential('jha_one'),
        ]);
    }

    private function busy(int $runners): void
    {
        $this->app->bind(RunnerCensus::class, fn () => new class($runners) implements RunnerCensus
        {
            public function __construct(private int $n) {}

            public function activeOn(Host $host): int
            {
                return $this->n;
            }
        });
    }

    public function test_cordon_and_uncordon(): void
    {
        $host = $this->host();

        $this->assertTrue(app(CordonHost::class)($host));
        $this->assertSame(HostStatus::Cordoned, $host->refresh()->status);

        // Heartbeats do not undo an operator's choice.
        $this->withToken('jha_one')->postJson('/agent/v1/heartbeat', ['cpu_total' => 4])->assertOk();
        $this->flushHeaders();
        $this->assertSame(HostStatus::Cordoned, $host->refresh()->status);

        $this->assertTrue(app(CordonHost::class)->uncordon($host));
        $this->assertSame(HostStatus::Online, $host->refresh()->status);

        $host->update(['status' => HostStatus::Cordoned, 'last_seen_at' => now()->subDay()]);
        app(CordonHost::class)->uncordon($host);
        $this->assertSame(HostStatus::Offline, $host->refresh()->status);
    }

    public function test_drain_waits_for_the_last_runner_then_cordons(): void
    {
        $this->busy(2);
        $host = $this->host();

        app(DrainHost::class)($host);
        $this->assertSame(HostStatus::Draining, $host->refresh()->status);

        $this->withToken('jha_one')->postJson('/agent/v1/heartbeat', [])->assertOk();
        $this->assertSame(HostStatus::Draining, $host->refresh()->status);

        $this->busy(0);
        $this->withToken('jha_one')->postJson('/agent/v1/heartbeat', [])->assertOk();
        $this->assertSame(HostStatus::Cordoned, $host->refresh()->status);
    }

    public function test_drain_of_an_idle_host_cordons_at_once(): void
    {
        $host = $this->host();
        app(DrainHost::class)($host);
        $this->assertSame(HostStatus::Cordoned, $host->refresh()->status);
    }

    public function test_revoke_kills_the_credential_and_pending_commands(): void
    {
        $host = $this->host();
        $command = app(HostDriver::class)->send($host, 'runner.start', []);
        $this->withToken('jha_one')->getJson('/agent/v1/me')->assertOk();

        $this->assertTrue(app(RevokeHost::class)($host));

        $this->withToken('jha_one')->getJson('/agent/v1/me')->assertUnauthorized();
        $this->withToken('jha_one')->postJson('/agent/v1/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-host.{$host->id}"])->assertUnauthorized();
        $host->refresh();
        $this->assertSame(HostStatus::Revoked, $host->status);
        $this->assertNull($host->credential_hash);
        $this->assertNotNull($host->revoked_at);
        $this->assertSame(CommandStatus::Failed, $command->refresh()->status);

        // Terminal: nothing brings it back.
        $this->assertFalse(app(CordonHost::class)($host));
        $this->assertFalse(app(RevokeHost::class)($host));
        $this->assertSame(HostStatus::Revoked, $host->refresh()->status);
    }

    public function test_ui_actions_are_for_admins(): void
    {
        $host = $this->host();

        Livewire::actingAs(User::factory()->create())->test(Index::class)
            ->assertDontSee('Cordon')
            ->call('revoke', $host->id)->assertForbidden();
        $this->assertSame(HostStatus::Online, $host->refresh()->status);

        $page = Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)->assertSee('Cordon');
        $page->call('cordon', $host->id)->assertSee('Resume');
        $this->assertSame(HostStatus::Cordoned, $host->refresh()->status);
        $page->call('uncordon', $host->id);
        $page->call('revoke', $host->id)->assertDontSee($host->name);
        $this->assertSame(HostStatus::Revoked, $host->refresh()->status);
    }

    public function test_local_host_is_off_by_default_and_enrolls_when_enabled(): void
    {
        $token = 'jhe_'.str_repeat('a', 40);

        $this->artisan('jogger:install')->expectsOutputToContain('disabled')->assertSuccessful();
        $this->assertSame(0, EnrollmentToken::query()->count());

        config(['jogger.local_host_enabled' => true, 'jogger.local_host_token' => 'short']);
        $this->artisan('jogger:install')->expectsOutputToContain('must start with')->assertSuccessful();

        config(['jogger.local_host_token' => $token]);
        $this->artisan('jogger:install')->expectsOutputToContain('may now enroll')->assertSuccessful();
        $this->artisan('jogger:install')->assertSuccessful();
        $this->assertSame(1, EnrollmentToken::query()->count());

        [$host] = app(EnrollHost::class)($token, 'whatever');
        $this->assertSame('local', $host->name);

        $this->artisan('jogger:install')->expectsOutputToContain('already enrolled')->assertSuccessful();
    }

    public function test_an_older_agent_can_be_updated_when_it_has_no_runners(): void
    {
        config(['jogger.version' => '1.1.0', 'jogger.cloud.agent_image' => 'ghcr.io/acme/jogger-agent:latest']);
        $host = $this->host();
        $host->update(['agent_version' => '1.0.0']);
        $this->busy(0);

        Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)
            ->assertSee('1.1.0 available')
            ->call('updateAgent', $host->id);

        $command = HostCommand::query()->where('host_id', $host->id)->sole();
        $this->assertSame('agent.update', $command->type);
        $this->assertSame(['image' => 'ghcr.io/acme/jogger-agent:1.1.0', 'version' => '1.1.0'], $command->payload);
    }

    public function test_no_update_while_runners_are_active_or_the_agent_is_current(): void
    {
        config(['jogger.version' => '1.1.0']);
        $host = $this->host();
        $host->update(['agent_version' => '1.0.0']);
        $this->busy(2);
        $this->assertFalse(app(RequestAgentUpdate::class)($host));

        $this->busy(0);
        $host->update(['agent_version' => '1.1.0']);
        $this->assertFalse(app(RequestAgentUpdate::class)($host->refresh()));

        config(['jogger.version' => 'dev']);
        $host->update(['agent_version' => '1.0.0']);
        $this->assertNull($host->refresh()->availableAgentVersion());
        $this->assertSame(0, HostCommand::query()->where('host_id', $host->id)->count());
    }
}
