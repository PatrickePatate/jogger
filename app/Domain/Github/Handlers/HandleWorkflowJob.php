<?php

declare(strict_types=1);

namespace App\Domain\Github\Handlers;

use App\Domain\Github\Contracts\WebhookHandler;
use App\Domain\Github\Data\WorkflowJobData;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Jobs\Actions\UpsertJobFromWebhook;

/**
 * Mirrors `workflow_job` events into Jogger's job table. Mirroring only: deciding who runs the job is the
 * scheduler's business (it listens to the status changes this causes).
 */
final class HandleWorkflowJob implements WebhookHandler
{
    public function __construct(private readonly UpsertJobFromWebhook $upsert) {}

    public function handle(WebhookDelivery $delivery): void
    {
        ($this->upsert)(WorkflowJobData::from($delivery->payload));
    }
}
