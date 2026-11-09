<?php

declare(strict_types=1);

namespace App\Domain\Github\Enums;

enum RepositorySelection: string
{
    case All = 'all';
    case Selected = 'selected';
}
