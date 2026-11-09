<?php

declare(strict_types=1);

namespace App\Domain\Github\Contracts;

use App\Domain\Github\Models\WebhookDelivery;

/**
 * Reacts to one kind of webhook event. Handlers MUST be idempotent: a delivery can be processed again
 * (replayed by an admin, redelivered by GitHub after a failure, or retried by the queue).
 */
interface WebhookHandler
{
    public function handle(WebhookDelivery $delivery): void;
}
