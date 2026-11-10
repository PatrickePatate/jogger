<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Gateway\RateLimitTracker;
use App\Domain\Github\Models\Installation;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Checks what the instance depends on. One failing check makes the whole report "degraded"; none throws. */
final class HealthCheck
{
    public function __construct(private readonly RateLimitTracker $github) {}

    /**
     * @return array{status: string, checks: array<string, array{ok: bool, detail: string}>}
     */
    public function report(): array
    {
        $checks = [
            'database' => $this->attempt(function (): string {
                DB::select('select 1');

                return 'reachable';
            }),
            'redis' => $this->attempt(function (): string {
                Redis::connection()->command('ping');

                return 'reachable';
            }),
            'reverb' => $this->attempt(function (): string {
                $host = (string) config('broadcasting.connections.reverb.options.host');
                $port = (int) config('broadcasting.connections.reverb.options.port');
                $socket = @fsockopen($host, $port, $code, $message, 2.0);
                if ($socket === false) {
                    throw new \RuntimeException("cannot connect to {$host}:{$port} ({$message})");
                }
                fclose($socket);

                return "listening on {$host}:{$port}";
            }),
            'queue' => $this->attempt(function (): string {
                $oldest = DB::table('workflow_jobs')->where('status', 'queued')->whereNull('unmatched_reason')->whereNull('runner_id')->min('queued_at');
                $lag = $oldest === null ? 0 : (int) abs(now()->diffInSeconds($oldest));
                if ($lag > 600) {
                    throw new \RuntimeException("a matched job has waited {$lag}s for a runner: are the queue workers and scheduler running?");
                }

                return "oldest waiting job: {$lag}s";
            }),
            'github' => $this->attempt(function (): string {
                $rejected = Installation::query()->where('status', InstallationStatus::Active->value)->get()
                    ->filter(fn (Installation $i) => $this->github->unauthorized((int) $i->github_installation_id));
                if ($rejected->isNotEmpty()) {
                    throw new \RuntimeException('credentials rejected for '.$rejected->pluck('account_login')->implode(', '));
                }

                return 'credentials accepted';
            }),
            'hosts' => $this->attempt(function (): string {
                $total = Host::query()->where('status', '!=', HostStatus::Revoked->value)->count();
                $online = Host::query()->where('status', HostStatus::Online->value)->count();
                if ($total > 0 && $online === 0) {
                    throw new \RuntimeException("none of the {$total} hosts is online");
                }

                return "{$online} of {$total} online";
            }),
        ];

        return [
            'status' => in_array(false, array_column($checks, 'ok'), true) ? 'degraded' : 'ok',
            'checks' => $checks,
        ];
    }

    /**
     * @param  callable(): string  $check
     * @return array{ok: bool, detail: string}
     */
    private function attempt(callable $check): array
    {
        try {
            return ['ok' => true, 'detail' => $check()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
