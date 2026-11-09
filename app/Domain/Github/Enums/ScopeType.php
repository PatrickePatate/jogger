<?php

declare(strict_types=1);

namespace App\Domain\Github\Enums;

/** Where a runner registers on GitHub: a single repository or a whole organization. */
enum ScopeType: string
{
    case Repository = 'repository';
    case Organization = 'organization';
}
