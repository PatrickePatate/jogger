<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\Github\Actions\ProcessWebhookDelivery;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\WebhookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/github. By the time we get here the signature is valid and the delivery id is new.
 * We only store the delivery and queue it: GitHub wants a 2xx within 10 seconds, so nothing else happens inline.
 */
final class GithubWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = (string) $request->header('X-GitHub-Event', '');
        $payload = $this->payload($request);

        if ($event === '' || strlen($event) > 40 || $payload === null) {
            abort(400, 'Malformed webhook: missing X-GitHub-Event or a JSON body.');
        }

        /** @var GithubApp $app */
        $app = $request->attributes->get('github_app');

        try {
            $delivery = WebhookDelivery::query()->create([
                'github_app_id' => $app->id,
                'delivery_id' => $request->attributes->get('delivery_id'),
                'event' => $event,
                'action' => is_string($payload['action'] ?? null) ? substr($payload['action'], 0, 40) : null,
                'payload' => $payload,
                'status' => DeliveryStatus::Received,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two identical deliveries raced past the duplicate check; the other request won.
            return response()->json(['status' => 'duplicate']);
        }

        ProcessWebhookDelivery::dispatch($delivery->id);

        return response()->json(['status' => 'accepted', 'delivery' => $delivery->id]);
    }

    /**
     * GitHub can send JSON (`application/json`) or form-encoded data with the JSON in a `payload` field.
     *
     * @return array<string, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        $body = $request->getContent();

        if (str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($body, $form);
            $body = is_string($form['payload'] ?? null) ? $form['payload'] : '';
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
