<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/** An in-app notice: an alert fired or resolved. */
class Notification extends Model
{
    use HasUuid7;

    public const string FIRED = 'fired';

    public const string RESOLVED = 'resolved';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'delivery_errors' => 'array',
        'created_at' => 'datetime',
    ];
}
