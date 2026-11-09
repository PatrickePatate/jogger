<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Access\Actions\InviteUser;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Github\Actions\ProcessWebhookDelivery;
use App\Domain\Github\Actions\ReplayWebhookDelivery;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Github\Webhooks\WebhookHandlers;
use App\Livewire\Github\Deliveries;
use App\Models\User;
use App\Support\Exceptions\DeliveryInProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CountingHandler;
use Tests\TestCase;

class DeliveriesPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(array $attributes = []): WebhookDelivery
    {
        return WebhookDelivery::factory()->create($attributes + [
            'payload' => ['action' => 'queued', 'repository' => ['full_name' => 'octo-org/example-workflow'], 'workflow_job' => ['id' => 399444496]],
        ]);
    }

    // ---------------------------------------------------------------- replay

    public function test_replaying_resets_the_delivery_queues_it_and_is_audited(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $delivery = $this->delivery(['status' => DeliveryStatus::Failed, 'error' => 'boom', 'processed_at' => now()]);

        app(ReplayWebhookDelivery::class)($admin, $delivery);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Received, $delivery->status);
        $this->assertNull($delivery->error);
        $this->assertNull($delivery->processed_at);
        $this->assertSame(1, $delivery->replay_count);
        $this->assertNotNull($delivery->replayed_at);
        Queue::assertPushed(ProcessWebhookDelivery::class, fn (ProcessWebhookDelivery $j) => $j->deliveryId === $delivery->id);

        $log = AuditLog::where('action', 'github.delivery_replayed')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($delivery->id, $log->target_id);
    }

    public function test_a_replay_runs_the_handler_again_end_to_end(): void
    {
        CountingHandler::reset();
        app(WebhookHandlers::class)->register('workflow_job', CountingHandler::class);
        $delivery = $this->delivery(['event' => 'workflow_job', 'status' => DeliveryStatus::Processed, 'processed_at' => now()]);

        app(ReplayWebhookDelivery::class)($this->admin(), $delivery); // sync queue: runs immediately

        $this->assertSame(1, CountingHandler::$calls);
        $this->assertSame(DeliveryStatus::Processed, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->replay_count); // still marked as replayed
    }

    public function test_replays_are_counted_and_a_delivery_in_flight_cannot_be_replayed(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $delivery = $this->delivery(['status' => DeliveryStatus::Processed]);

        app(ReplayWebhookDelivery::class)($admin, $delivery);
        $delivery->forceFill(['status' => DeliveryStatus::Processed])->save();
        app(ReplayWebhookDelivery::class)($admin, $delivery);
        $this->assertSame(2, $delivery->fresh()->replay_count);

        $delivery->forceFill(['status' => DeliveryStatus::Processing])->save();
        $this->expectException(DeliveryInProgress::class);
        app(ReplayWebhookDelivery::class)($admin, $delivery);
    }

    public function test_the_replay_button_queues_the_delivery_and_marks_it_in_the_list(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['event' => 'workflow_job', 'status' => DeliveryStatus::Failed, 'error' => 'boom']);

        Livewire::actingAs($this->admin())->test(Deliveries::class)
            ->assertDontSeeHtml('data-testid="replayed"')
            ->call('replay', $delivery->id)
            ->assertDispatched('toast', type: 'success', message: 'Replay queued.')
            ->assertSeeHtml('data-testid="replayed"')
            ->assertSee('Replayed');

        Queue::assertPushed(ProcessWebhookDelivery::class);
    }

    public function test_replaying_a_busy_delivery_shows_an_error_toast(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['status' => DeliveryStatus::Processing]);

        Livewire::actingAs($this->admin())->test(Deliveries::class)->call('replay', $delivery->id)->assertDispatched('toast', type: 'error');

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- the page

    public function test_the_list_shows_event_repository_and_status_without_loading_payloads(): void
    {
        $this->delivery(['event' => 'workflow_job', 'action' => 'queued', 'status' => DeliveryStatus::Processed]);
        $this->delivery(['event' => 'installation', 'action' => 'created', 'status' => DeliveryStatus::Failed, 'error' => 'RuntimeException: nope', 'payload' => ['action' => 'created']]);
        $this->delivery(['event' => 'star', 'status' => DeliveryStatus::Ignored]);

        Livewire::actingAs($this->admin())->test(Deliveries::class)
            ->assertSee('workflow_job.queued')->assertSee('octo-org/example-workflow')
            ->assertSee('installation.created')->assertSee('RuntimeException: nope')
            ->assertSee('Processed')->assertSee('Failed')->assertSee('No handler')
            ->assertDontSee('399444496'); // payload bodies are not in the list
    }

    public function test_filters_by_event_and_status(): void
    {
        $this->delivery(['event' => 'workflow_job', 'status' => DeliveryStatus::Processed]);
        $this->delivery(['event' => 'installation', 'action' => 'created', 'status' => DeliveryStatus::Failed]);

        Livewire::actingAs($this->admin())->test(Deliveries::class)
            ->set('event', 'installation')->assertSee('installation.created')->assertDontSee('workflow_job.queued')
            ->set('event', '')->set('status', 'processed')->assertSee('workflow_job.queued')->assertDontSee('installation.created')
            ->set('status', 'failed')->assertSee('installation.created')->assertDontSee('workflow_job.queued')
            ->set('status', '')->set('event', 'star')->assertSee('No deliveries match');
    }

    public function test_the_payload_viewer_shows_pretty_json_and_the_delivery_id(): void
    {
        $delivery = $this->delivery(['delivery_id' => '72d3162e-cc78-11e3-81ab-4c9367dc0958']);

        Livewire::actingAs($this->admin())->test(Deliveries::class)
            ->call('view', $delivery->id)
            ->assertDispatched('open-modal', name: 'delivery')
            ->assertSee('72d3162e-cc78-11e3-81ab-4c9367dc0958')
            ->assertSeeHtml('data-testid="payload"')
            ->assertSee('"full_name": "octo-org/example-workflow"')
            ->assertSee('399444496');
    }

    public function test_huge_payloads_are_cut_off_in_the_viewer_only(): void
    {
        $delivery = $this->delivery(['payload' => ['blob' => str_repeat('a', 300_000)]]);

        Livewire::actingAs($this->admin())->test(Deliveries::class)
            ->call('view', $delivery->id)->assertSee('Showing the first 200 KB');

        $this->assertSame(300_000, strlen(WebhookDelivery::find($delivery->id)->payload['blob']));
    }

    public function test_empty_states(): void
    {
        Livewire::actingAs($this->admin())->test(Deliveries::class)->assertSee('No deliveries yet');
    }

    public function test_the_page_polls_so_new_deliveries_appear_on_their_own(): void
    {
        Livewire::actingAs($this->admin())->test(Deliveries::class)->assertSeeHtml('wire:poll.5s');
    }

    public function test_pagination_shows_25_per_page(): void
    {
        WebhookDelivery::factory()->count(30)->create();

        $component = Livewire::actingAs($this->admin())->test(Deliveries::class);

        $this->assertCount(25, $component->viewData('deliveries')->items());
        $this->assertSame(30, $component->viewData('deliveries')->total());
    }

    // ---------------------------------------------------------------- access

    public function test_only_administrators_can_open_the_page_or_use_its_actions(): void
    {
        $member = User::factory()->create();
        $delivery = $this->delivery();
        Queue::fake();

        $this->actingAs($member)->get('/github/deliveries')->assertForbidden();
        Livewire::actingAs($member)->test(Deliveries::class)->call('replay', $delivery->id)->assertForbidden();
        Livewire::actingAs($member)->test(Deliveries::class)->call('view', $delivery->id)->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, $delivery->fresh()->replay_count);

        auth()->logout();
        $this->get('/github/deliveries')->assertRedirect(route('login'));
        $this->assertNotNull(app(InviteUser::class)); // (keeps the import honest)
    }

    public function test_the_page_is_linked_from_the_navigation_for_admins(): void
    {
        $this->actingAs($this->admin())->get('/github/deliveries')->assertOk()->assertSee('href="'.route('github.deliveries').'"', false);
    }
}
