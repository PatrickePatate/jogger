<?php

declare(strict_types=1);

namespace App\Domain\Access\Contracts;

/** A resource teams can be granted access to (installations, runner pools). */
interface Grantable
{
    /** Stable key stored in team_grants.grantable_type, e.g. "pool". */
    public function grantableType(): string;
}
