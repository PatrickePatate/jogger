<?php

declare(strict_types=1);

namespace Tests\Feature\Hosts;

use App\Domain\Hosts\Actions\CreateEnrollmentToken;
use App\Domain\Hosts\Actions\EnrollHost;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Events\HostStatusChanged;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use App\Livewire\Hosts\EnrollModal;
use App\Livewire\Hosts\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class HostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([HostStatusChanged::class]);
    }

    /**
     * @return array{0: Host, 1: string}
     */
    private function enrolled(): array
    {
        [$plain] = app(CreateEnrollmentToken::class)(User::factory()->admin()->create());

        return app(EnrollHost::class)($plain, 'build-01');
    }

    public function test_heartbeat_updates_capacity_and_marks_a_pending_host_online(): void
    {
        [$host, $credential] = $this->enrolled();

        $this->withToken($credential)->postJson('/agent/v1/heartbeat', [
            'cpu_total' => 8, 'cpu_used' => 1.5, 'memory_total_mb' => 16000, 'memory_used_mb' => 4000, 'disk_free_gb' => 120,
            'docker_version' => '27.3.1', 'agent_version' => '0.1.0', 'arch' => 'x64', 'os' => 'linux',
        ])->assertOk()->assertJsonPath('status', 'online');

        $host->refresh();
        $this->assertSame(HostStatus::Online, $host->status);
        $this->assertSame(1.5, $host->cpu_used);
        $this->assertSame(120, $host->disk_free_gb);
        $this->assertNotNull($host->last_seen_at);
        Event::assertDispatchedTimes(HostStatusChanged::class, 1);
    }

    public function test_steady_heartbeats_do_not_broadcast_and_unknown_fields_are_ignored(): void
    {
        [$host, $credential] = $this->enrolled();
        $this->withToken($credential)->postJson('/agent/v1/heartbeat', [])->assertOk();
        $this->withToken($credential)->postJson('/agent/v1/heartbeat', ['name' => 'hacked', 'status' => 'revoked', 'cpu_used' => 2])->assertOk();

        $host->refresh();
        $this->assertNotSame('hacked', $host->name);
        $this->assertSame(HostStatus::Online, $host->status);
        Event::assertDispatchedTimes(HostStatusChanged::class, 1);
    }

    public function test_heartbeat_validates_and_requires_a_credential(): void
    {
        [, $credential] = $this->enrolled();

        $this->postJson('/agent/v1/heartbeat', [])->assertUnauthorized();
        $this->withToken($credential)->postJson('/agent/v1/heartbeat', ['cpu_used' => 'lots'])->assertUnprocessable();
    }

    public function test_hosts_silent_for_30_seconds_go_offline_and_return_on_heartbeat(): void
    {
        [$host, $credential] = $this->enrolled();
        $this->withToken($credential)->postJson('/agent/v1/heartbeat', [])->assertOk();
        Event::fake([HostStatusChanged::class]);

        $this->travel(20)->seconds();
        $this->artisan('jogger:mark-offline-hosts')->assertSuccessful();
        $this->assertSame(HostStatus::Online, $host->refresh()->status);

        $this->travel(15)->seconds();
        $this->artisan('jogger:mark-offline-hosts')->assertSuccessful();
        $this->assertSame(HostStatus::Offline, $host->refresh()->status);
        Event::assertDispatchedTimes(HostStatusChanged::class, 1);

        $this->artisan('jogger:mark-offline-hosts')->assertSuccessful();
        Event::assertDispatchedTimes(HostStatusChanged::class, 1);

        $this->withToken($credential)->postJson('/agent/v1/heartbeat', [])->assertOk()->assertJsonPath('status', 'online');
        $this->assertSame(HostStatus::Online, $host->refresh()->status);
        Event::assertDispatchedTimes(HostStatusChanged::class, 2);
    }

    public function test_cordoned_hosts_keep_their_state_and_pending_hosts_are_left_alone(): void
    {
        $cordoned = Host::factory()->create(['status' => HostStatus::Cordoned, 'last_seen_at' => now()->subMinutes(5)]);
        $pending = Host::factory()->create(['status' => HostStatus::Pending, 'last_seen_at' => null]);

        $this->artisan('jogger:mark-offline-hosts')->assertSuccessful();

        $this->assertSame(HostStatus::Cordoned, $cordoned->refresh()->status);
        $this->assertSame(HostStatus::Pending, $pending->refresh()->status);
    }

    public function test_cordoned_host_heartbeat_does_not_clear_the_cordon(): void
    {
        [$host, $credential] = $this->enrolled();
        $host->update(['status' => HostStatus::Cordoned]);

        $this->withToken($credential)->postJson('/agent/v1/heartbeat', [])->assertOk()->assertJsonPath('status', 'cordoned');
    }

    public function test_the_offline_check_is_scheduled_every_ten_seconds(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('jogger:mark-offline-hosts')->assertSuccessful();
    }

    public function test_the_hosts_page_lists_hosts_for_any_signed_in_user(): void
    {
        Host::factory()->create(['name' => 'build-box', 'status' => HostStatus::Online, 'agent_version' => '0.1.0']);
        Host::factory()->create(['name' => 'old-box', 'status' => HostStatus::Offline]);
        $this->actingAs(User::factory()->create());

        $this->get('/hosts')->assertOk()->assertSee('build-box')->assertSee('old-box')->assertSee('Online')->assertSee('Offline');
        Livewire::test(Index::class)->assertSee('build-box')->assertDontSee('Add host');
    }

    public function test_admins_see_the_add_host_button_and_guests_are_redirected(): void
    {
        User::factory()->create();
        $this->get('/hosts')->assertRedirect('/login');

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(Index::class)->assertSee('Add host')->assertSee('No hosts yet');
    }

    public function test_the_page_listens_for_host_status_events(): void
    {
        $this->actingAs(User::factory()->create());

        Host::factory()->create(['name' => 'first-box']);
        $component = Livewire::test(Index::class)->assertSee('first-box');
        Host::factory()->create(['name' => 'second-box']);

        $component->dispatch('echo-private:hosts,.host.status-changed')->assertSee('second-box');
    }

    public function test_the_enroll_modal_shows_a_docker_command_with_token_and_server_url(): void
    {
        config(['app.url' => 'https://jogger.example.com/']);
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test(EnrollModal::class)->set('hostName', 'build-01')->call('create');

        $token = $component->get('plainToken');
        $this->assertStringStartsWith('jhe_', $token);
        $component->assertSee("JOGGER_ENROLL_TOKEN={$token}", false)
            ->assertSee('JOGGER_SERVER=https://jogger.example.com', false)
            ->assertSee('docker run', false)
            ->assertSee('Valid for 1 hour');
        $this->assertSame('build-01', EnrollmentToken::query()->sole()->host_name);
    }

    public function test_the_modal_stepper_advances_as_the_agent_connects(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $component = Livewire::test(EnrollModal::class)->call('create')->assertSee('1 of 4');

        $credential = $this->postJson('/agent/v1/enroll', [
            'token' => $component->get('plainToken'), 'hostname' => 'edge-7',
            'facts' => ['cpu_total' => 4, 'memory_total_mb' => 8192],
        ])->assertCreated()->json('credential');

        $component->call('$refresh')->assertSee('3 of 4')->assertSee('edge-7');

        $this->withToken($credential)->postJson('/agent/v1/heartbeat', ['cpu_total' => 4, 'memory_total_mb' => 8192])->assertOk();

        $component->call('$refresh')->assertSee('4 of 4')->assertSee('4 CPUs');
    }

    public function test_only_admins_can_create_enrollment_tokens_from_the_modal(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EnrollModal::class)->call('create')->assertForbidden();
        $this->assertSame(0, EnrollmentToken::query()->count());
    }

    public function test_host_name_is_validated(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EnrollModal::class)->set('hostName', 'bad name!')->call('create')->assertHasErrors('hostName');
    }

    public function test_the_hosts_channel_is_open_to_signed_in_users_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-hosts'])->assertOk();
    }
}
