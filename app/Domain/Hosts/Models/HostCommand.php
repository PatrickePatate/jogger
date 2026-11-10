<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Models;

use App\Domain\Hosts\Enums\CommandStatus;
use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostCommand extends Model
{
    use HasUuid7;

    /** A command handed out this many times without an ack is given up on. */
    public const MAX_ATTEMPTS = 5;

    /** Seconds after which a sent but un-acked command is offered again. */
    public const REDELIVER_AFTER = 30;

    protected $guarded = [];

    protected $casts = [
        'status' => CommandStatus::class,
        'payload' => 'array',
        'result' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
        'acked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Host, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }
}
