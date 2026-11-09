<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * UUIDv7 primary keys: time-ordered, so inserts stay index-friendly.
 * Laravel's HasUuids already generates v7; this trait names the convention.
 */
trait HasUuid7
{
    use HasUuids;
}
