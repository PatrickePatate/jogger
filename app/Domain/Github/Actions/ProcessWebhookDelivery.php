<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Github\Webhooks\WebhookHandlers;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the handler for one stored delivery and records the outcome. Safe to dispatch any number of
 * times for the same delivery: a worker first *claims* it (received|failed → processing) so only one runs.
 */
final class ProcessWebhookDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $deliveryId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(WebhookHandlers $handlers): void
    {
        $claimed = WebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereIn('status', [DeliveryStatus::Received->value, DeliveryStatus::Failed->value])
            ->update(['status' => DeliveryStatus::Processing->value, 'error' => null]);

        if ($claimed === 0) {
            return; // already processed, being processed, or gone
        }

        $delivery = WebhookDelivery::query()->findOrFail($this->deliveryId);
        $handler = $handlers->for($delivery->event);

        if ($handler === null) {
            $this->finish($delivery, DeliveryStatus::Ignored);

            return;
        }

        try {
            $handler->handle($delivery);
        } catch (GithubRateLimited $e) {
            // Not a failure: put it back and try again when GitHub allows.
            $delivery->forceFill(['status' => DeliveryStatus::Received])->save();
            $this->release($e->retryAfterSeconds ?? 60);

            return;
        } catch (Throwable $e) {
            $this->markFailed($delivery, $e);

            throw $e; // let the queue retry with backoff
        }

        $this->finish($delivery, DeliveryStatus::Processed);
    }

    /** Called by the queue after the last attempt (or a timeout/kill) so a row is never left "processing". */
    public function failed(Throwable $e): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status === DeliveryStatus::Processing) {
            $this->markFailed($delivery, $e);
        }
    }

    private function finish(WebhookDelivery $delivery, DeliveryStatus $status): void
    {
        $delivery->forceFill(['status' => $status, 'error' => null, 'processed_at' => now()])->save();
    }

    private function markFailed(WebhookDelivery $delivery, Throwable $e): void
    {
        $delivery->forceFill(['status' => DeliveryStatus::Failed, 'error' => Str::limit($e::class.': '.$e->getMessage(), 1000, '')])->save();
    }
}
