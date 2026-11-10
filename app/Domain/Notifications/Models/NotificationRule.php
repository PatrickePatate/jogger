<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/** Where alerts go besides the in-app bell: an e-mail address or a webhook URL, for one alert type or all. */
class NotificationRule extends Model
{
    use HasUuid7;

    public const string MAIL = 'mail';

    public const string WEBHOOK = 'webhook';

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'enabled' => 'boolean',
        'last_error_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];
}
