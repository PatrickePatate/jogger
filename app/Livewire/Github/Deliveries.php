<?php

declare(strict_types=1);

namespace App\Livewire\Github;

use App\Domain\Github\Actions\ReplayWebhookDelivery;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\WebhookDelivery;
use App\Models\User;
use App\Support\Exceptions\JoggerException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Webhook deliveries')]
final class Deliveries extends Component
{
    use WithPagination;

    /** Payloads larger than this are cut off in the viewer (the stored copy is complete). */
    private const VIEW_LIMIT = 200_000;

    #[Url]
    public string $event = '';

    #[Url]
    public string $status = '';

    public ?string $viewingId = null;

    public function updatedEvent(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function view(string $deliveryId): void
    {
        Gate::authorize('admin');

        $this->viewingId = WebhookDelivery::query()->findOrFail($deliveryId)->id;
        $this->dispatch('open-modal', name: 'delivery');
    }

    public function replay(string $deliveryId, ReplayWebhookDelivery $replay): void
    {
        Gate::authorize('admin');

        try {
            /** @var User $admin */
            $admin = Auth::user();
            $replay($admin, WebhookDelivery::query()->findOrFail($deliveryId));
        } catch (JoggerException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Replay queued.');
    }

    public function render(): View
    {
        $viewing = $this->viewingId ? WebhookDelivery::query()->find($this->viewingId) : null;
        $json = $viewing ? (string) json_encode($viewing->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        return view('livewire.github.deliveries', [
            // The payload column is left out of the list query: it can be megabytes.
            'deliveries' => WebhookDelivery::query()
                ->select(['id', 'delivery_id', 'event', 'action', 'status', 'error', 'received_at', 'processed_at', 'replayed_at', 'replay_count'])
                ->selectRaw("payload->'repository'->>'full_name' as repository")
                ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->orderByDesc('received_at')
                ->paginate(25),
            'events' => WebhookDelivery::query()->distinct()->orderBy('event')->pluck('event'),
            'statuses' => DeliveryStatus::cases(),
            'viewing' => $viewing,
            'json' => strlen($json) > self::VIEW_LIMIT ? substr($json, 0, self::VIEW_LIMIT) : $json,
            'truncated' => strlen($json) > self::VIEW_LIMIT,
            'retentionDays' => (int) config('jogger.delivery_retention_days'),
        ]);
    }
}
