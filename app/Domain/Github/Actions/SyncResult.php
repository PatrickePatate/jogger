<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

final readonly class SyncResult
{
    public function __construct(public int $added, public int $updated, public int $removed, public int $total) {}

    public function summary(): string
    {
        if ($this->added + $this->updated + $this->removed === 0) {
            return "Already up to date ({$this->total} repositories).";
        }

        return "Synced {$this->total} repositories: {$this->added} added, {$this->updated} updated, {$this->removed} removed.";
    }
}
