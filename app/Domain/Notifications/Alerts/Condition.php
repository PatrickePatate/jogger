<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Alerts;

/** Something that is wrong right now. `subject` identifies what it is about, so the same problem is one alert. */
final readonly class Condition
{
    public function __construct(
        public AlertType $type,
        public string $subject,
        public string $title,
        public string $body,
    ) {}
}
