<?php

declare(strict_types=1);

namespace App\Support\Ui;

use Spatie\LaravelData\Data;

final class StepData extends Data
{
    public function __construct(
        public string $label,
        public ?string $detail = null,
        public StepState $state = StepState::Pending,
    ) {}
}
