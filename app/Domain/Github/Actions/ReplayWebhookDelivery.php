<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Access\Audit;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\WebhookDelivery;
use App\Models\User;
use App\Support\Exceptions\DeliveryInProgress;
use Illuminate\Support\Facades\DB;

/** Re-runs a stored delivery through its handler (e.g. after fixing a bug that made it fail). */
final class ReplayWebhookDelivery
{
    /**
     * @throws DeliveryInProgress when a worker is processing it right now
     */
    public function __invoke(User $admin, WebhookDelivery $delivery): void
    {
        DB::transaction(function () use ($admin, $delivery): void {
            $locked = WebhookDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === DeliveryStatus::Processing) {
                throw new DeliveryInProgress;
            }

            $locked->forceFill([
                'status' => DeliveryStatus::Received,
                'error' => null,
                'processed_at' => null,
                'replayed_at' => now(),
                'replay_count' => $locked->replay_count + 1,
            ])->save();

            Audit::record('github.delivery_replayed', $locked, ['event' => $locked->event, 'action' => $locked->action, 'delivery_id' => $locked->delivery_id], $admin);
        });

        ProcessWebhookDelivery::dispatch($delivery->getKey());
    }
}
