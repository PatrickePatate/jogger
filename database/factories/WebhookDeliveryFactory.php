<?php

namespace Database\Factories;

use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => (string) Str::uuid(),
            'event' => 'workflow_job',
            'action' => 'queued',
            'payload' => ['action' => 'queued'],
            'status' => DeliveryStatus::Received,
            'received_at' => now(),
        ];
    }
}
