<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Cloud\Actions\ScaleCloudHosts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Asks for cloud servers now, when a pool with cloud templates found no host with room for a runner. Identical
 * requests within the window collapse into one.
 */
final class ScaleCloudHostsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 10;

    public function uniqueId(): string
    {
        return 'scale-cloud-hosts';
    }

    /** Dispatches only when the pool has a cloud template; other pools never wait for a cloud server. */
    public static function dispatchForPool(string $poolId): void
    {
        if (DB::table('host_template_pool')->where('runner_pool_id', $poolId)->exists()) {
            self::dispatch();
        }
    }

    public function handle(ScaleCloudHosts $scale): void
    {
        $scale();
    }
}
