<?php

declare(strict_types=1);

namespace App\Domain\Github\Webhooks;

use App\Domain\Github\Contracts\WebhookHandler;
use App\Domain\Github\Models\WebhookDelivery;

/** GitHub sends a `ping` when a webhook is created; receiving it proves the endpoint works. Nothing to do. */
final class HandlePing implements WebhookHandler
{
    public function handle(WebhookDelivery $delivery): void {}
}
