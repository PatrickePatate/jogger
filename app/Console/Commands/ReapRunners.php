<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Runners\Actions\ReapStuckRunners;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:reap-runners')]
#[Description('End runners that are stuck: never started, lost with their host, idle too long, or never confirmed stopped')]
final class ReapRunners extends Command
{
    public function handle(ReapStuckRunners $reap): int
    {
        $result = $reap();
        $this->info(collect($result)->map(fn (int $n, string $rule) => "{$rule}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
