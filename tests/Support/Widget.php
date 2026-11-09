<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\Concerns\VisibleTo;
use App\Domain\Access\Contracts\Grantable;
use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a grantable resource (installations / runner pools arrive in later tasks).
 * Needs a temporary `widgets (id uuid primary key, name text)` table, created by the tests that use it.
 */
#[Fillable(['name'])]
class Widget extends Model implements Grantable
{
    use HasUuid7, VisibleTo;

    public $timestamps = false;
}
