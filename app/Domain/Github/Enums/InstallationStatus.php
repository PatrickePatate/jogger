<?php

declare(strict_types=1);

namespace App\Domain\Github\Enums;

enum InstallationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}
