<?php

declare(strict_types=1);

namespace App\Domain\Pools\Enums;

/** How jobs get a Docker daemon: not at all, or a private rootless daemon next to the runner. */
enum DockerMode: string
{
    case None = 'none';
    case Dind = 'dind';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No Docker',
            self::Dind => 'Docker in Docker (isolated)',
        };
    }
}
