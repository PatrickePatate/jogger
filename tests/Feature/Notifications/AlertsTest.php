<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Gateway\RateLimitTracker;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Notifications\Actions\EvaluateAlerts;
use App\Domain\Notifications\Alerts\AlertType;
use App\Domain\Notifications\Channels\Dispatcher;
use App\Domain\Notifications\Models\Alert;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Settings\Notifications;
use App\Livewire\Shared\NotificationBell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-10 12:00:00');
    }

    /** @return array{fired: int, resolved: int} */
    private function evaluate(): array
    {
        return app(EvaluateAlerts::class)();
    }

    public function test_host_offline_fires_once_then_resolves(): void
    {
        $host = Host::factory()->create(['name' => 'build-01', 'status' => HostStatus::Offline]);

        $this->assertSame(['fired' => 1, 'resolved' => 0], $this->evaluate());
        $this->assertSame(['fired' => 0, 'resolved' => 0], $this->evaluate());
        $this->assertSame(1, Notification::query()->where('kind', 'fired')->count());
        $this->assertSame('Host build-01 is offline', Notification::query()->firstOrFail()->title);

        $host->forceFill(['status' => HostStatus::Online])->save();
        $this->assertSame(['fired' => 0, 'resolved' => 1], $this->evaluate());
        $this->assertSame('Resolved: Host build-01 is offline', Notification::query()->where('kind', 'resolved')->firstOrFail()->title);
        $this->assertSame(Alert::RESOLVED, Alert::query()->firstOrFail()->status);
    }

    public function test_a_flapping_alert_is_announced_once_per_dedupe_window(): void
    {
        $host = Host::factory()->create(['status' => HostStatus::Offline]);
        $this->evaluate();
        $host->forceFill(['status' => HostStatus::Online])->save();
        $this->evaluate();

        Carbon::setTestNow('2026-11-10 12:20:00'); // inside the 60-minute window
        $host->forceFill(['status' => HostStatus::Offline])->save();
        $this->assertSame(1, $this->evaluate()['fired']);
        $host->forceFill(['status' => HostStatus::Online])->save();
        $this->evaluate();
        $this->assertSame(2, Notification::query()->count()); // still only the first fired + resolved pair

        Carbon::setTestNow('2026-11-10 13:30:00'); // window over
        $host->forceFill(['status' => HostStatus::Offline])->save();
        $this->evaluate();
        $this->assertSame(2, Notification::query()->where('kind', 'fired')->count());
        $this->assertSame(1, Alert::query()->count());
    }

    public function test_each_other_alert_type_fires_on_its_trigger(): void
    {
        $installation = Installation::factory()->create();
        $pool = RunnerPool::factory()->create(['installation_id' => $installation->id]);
        Host::factory()->create(['status' => HostStatus::Online, 'disk_free_gb' => 3]);
        Host::factory()->create(['status' => HostStatus::Online, 'disk_free_gb' => 300]);
        Runner::factory()->count(3)->create(['pool_id' => $pool->id, 'state' => RunnerState::Failed, 'exit_reason' => 'start_failed', 'finished_at' => now()->subMinutes(2)]);
        app(RateLimitTracker::class)->markUnauthorized((int) $installation->github_installation_id);
        WebhookDelivery::factory()->count(5)->create(['status' => DeliveryStatus::Failed, 'received_at' => now()->subMinutes(3)]);

        $this->evaluate();

        $this->assertEqualsCanonicalizing(
            [AlertType::DiskPressure->value, AlertType::PoolCannotProvision->value, AlertType::GithubAuthFailure->value, AlertType::WebhookFailures->value],
            Alert::query()->where('status', Alert::FIRING)->pluck('type')->all(),
        );

        // A runner that starts after the failures means the pool recovered.
        Runner::factory()->create(['pool_id' => $pool->id, 'state' => RunnerState::Idle, 'started_at' => now()]);
        $this->evaluate();
        $this->assertSame(Alert::RESOLVED, Alert::query()->where('type', AlertType::PoolCannotProvision->value)->firstOrFail()->status);
    }

    public function test_the_webhook_channel_signs_its_payload(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok')]);
        NotificationRule::query()->create(['channel' => 'webhook', 'target' => 'https://hooks.example.com/jogger', 'secret' => 'signing-secret']);
        NotificationRule::query()->create(['channel' => 'webhook', 'target' => 'https://hooks.example.com/other', 'secret' => 'x', 'alert_type' => AlertType::DiskPressure->value]);
        Host::factory()->create(['status' => HostStatus::Offline]);

        $this->evaluate();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'signing-secret');

            return $request->url() === 'https://hooks.example.com/jogger'
                && hash_equals($expected, $request->header(Dispatcher::SIGNATURE_HEADER)[0])
                && $request['event'] === 'alert.fired' && $request['alert']['type'] === 'host_offline';
        });
    }

    public function test_a_failed_mail_falls_back_to_in_app_and_records_the_error(): void
    {
        $rule = NotificationRule::query()->create(['channel' => 'mail', 'target' => 'oncall@example.com']);
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Connection could not be established with host smtp.example.com'));
        Host::factory()->create(['status' => HostStatus::Offline]);

        $this->evaluate();

        $notification = Notification::query()->firstOrFail();
        $this->assertSame('mail', $notification->delivery_errors[0]['channel']);
        $this->assertStringContainsString('smtp.example.com', (string) $rule->fresh()->last_error);
        $this->assertNotNull($rule->fresh()->last_error_at);
    }

    public function test_mail_is_sent_to_the_rule_address(): void
    {
        NotificationRule::query()->create(['channel' => 'mail', 'target' => 'oncall@example.com']);
        Host::factory()->create(['name' => 'build-01', 'status' => HostStatus::Offline]);

        $this->evaluate();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('[Jogger] Host build-01 is offline', $sent[0]->getOriginalMessage()->getSubject());
        $this->assertNull(Notification::query()->firstOrFail()->delivery_errors);
    }

    public function test_the_bell_counts_unread_notices_for_administrators(): void
    {
        Host::factory()->create(['status' => HostStatus::Offline]);
        $this->evaluate();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(NotificationBell::class)
            ->assertViewHas('unread', 1)->assertSee('is offline')
            ->call('markRead')->assertViewHas('unread', 0);

        Livewire::actingAs(User::factory()->create())->test(NotificationBell::class)->assertForbidden();
    }

    public function test_rules_are_managed_by_administrators_only(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Notifications::class)->assertForbidden();

        $page = Livewire::actingAs(User::factory()->admin()->create())->test(Notifications::class)
            ->set('channel', 'mail')->set('target', 'not-an-address')->call('add')->assertHasErrors('target')
            ->set('target', 'oncall@example.com')->call('add')->assertHasNoErrors()->assertSet('newSecret', null)
            ->set('channel', 'webhook')->set('target', 'https://hooks.example.com/x')->set('alertType', 'host_offline')->call('add')->assertHasNoErrors();

        $this->assertSame(40, strlen((string) $page->get('newSecret')));
        $this->assertSame(2, NotificationRule::query()->count());
        $webhook = NotificationRule::query()->where('channel', 'webhook')->firstOrFail();
        $this->assertSame($page->get('newSecret'), $webhook->secret);

        $page->call('toggle', $webhook->id);
        $this->assertFalse($webhook->fresh()->enabled);
        $page->call('delete', $webhook->id);
        $this->assertSame(1, NotificationRule::query()->count());
    }
}
