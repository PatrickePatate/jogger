<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Models;

use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Whether a host has (or is fetching, or failed to fetch) a runner image. */
class HostImage extends Model
{
    use HasUuid7;

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = ['pulled_at' => 'datetime'];

    /**
     * @return BelongsTo<Host, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }
}
