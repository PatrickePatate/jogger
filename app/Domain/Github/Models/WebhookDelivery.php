<?php

declare(strict_types=1);

namespace App\Domain\Github\Models;

use App\Domain\Github\Enums\DeliveryStatus;
use App\Support\Concerns\HasUuid7;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stored webhook delivery (kept 14 days) so it can be inspected and replayed. */
#[UseFactory(WebhookDeliveryFactory::class)]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUuid7;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'status' => DeliveryStatus::class,
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'replayed_at' => 'datetime',
        'replay_count' => 'integer',
    ];

    /**
     * @return BelongsTo<GithubApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class, 'github_app_id');
    }
}
