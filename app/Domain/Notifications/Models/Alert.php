<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/** A condition that needs attention, tracked from the moment it starts until it clears. */
class Alert extends Model
{
    use HasUuid7;

    public const string FIRING = 'firing';

    public const string RESOLVED = 'resolved';

    protected $guarded = [];

    protected $casts = [
        'fired_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notified_at' => 'datetime',
        'episode_notified' => 'boolean',
    ];
}
