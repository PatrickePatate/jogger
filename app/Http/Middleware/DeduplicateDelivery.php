<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Github\Actions\ProcessWebhookDelivery;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\WebhookDelivery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 200 to a delivery we already have, without storing or running it twice.
 *
 * GitHub's redelivery re-sends the *same* X-GitHub-Delivery id, so "seen before" must not mean "never again":
 * a delivery we finished is acknowledged and skipped, but one still waiting or that failed is queued once more
 * (the worker claims it atomically, so a double dispatch cannot run it twice).
 */
final class DeduplicateDelivery
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = strtolower((string) $request->header('X-GitHub-Delivery', ''));

        if (! Str::isUuid($id)) {
            abort(400, 'Missing or malformed X-GitHub-Delivery header.');
        }

        $existing = WebhookDelivery::query()->where('delivery_id', $id)->first();

        if ($existing !== null) {
            if (in_array($existing->status, [DeliveryStatus::Received, DeliveryStatus::Failed], true)) {
                ProcessWebhookDelivery::dispatch($existing->id);
            }

            return response()->json(['status' => 'duplicate', 'delivery' => $existing->id]);
        }

        $request->attributes->set('delivery_id', $id);

        return $next($request);
    }
}
