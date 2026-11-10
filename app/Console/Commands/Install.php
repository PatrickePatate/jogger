<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hosts\Actions\EnrollLocalHost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:install')]
#[Description('Prepare a fresh or upgraded install: run migrations and, when enabled, let the local host agent enroll')]
final class Install extends Command
{
    public function handle(EnrollLocalHost $local): int
    {
        // --isolated holds a lock in the cache store: when several replicas start together, one migrates.
        $this->call('migrate', ['--force' => true, '--isolated' => true]);

        match ($local()) {
            'disabled' => $this->line('Local host: disabled (set JOGGER_LOCAL_HOST=true to run jobs on this machine).'),
            'invalid_token' => $this->warn('Local host: JOGGER_LOCAL_HOST_TOKEN must start with "jhe_" followed by at least 32 random characters.'),
            'already_enrolled' => $this->line('Local host: already enrolled.'),
            'ready' => $this->info('Local host: the agent may now enroll (start it with `--profile local-host`).'),
        };

        return self::SUCCESS;
    }
}
