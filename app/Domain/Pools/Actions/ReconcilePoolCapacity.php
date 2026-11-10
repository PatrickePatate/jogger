<?php

declare(strict_types=1);

namespace App\Domain\Pools\Actions;

use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolDemand;
use App\Domain\Runners\Actions\ProvisionRunner;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use App\Support\Exceptions\HostUnavailable;
use Illuminate\Support\Facades\Log;

/**
 * Brings a pool up to what it needs: its warm minimum plus one runner per job waiting for it, never beyond
 * max_runners (ProvisionRunner enforces the ceiling under a lock). Scaling down is the reaper's idle timeout.
 */
final class ReconcilePoolCapacity
{
    /** Per pool and per pass, so one pool cannot starve the others or burn the GitHub rate limit. */
    public const MAX_PER_PASS = 10;

    public function __construct(private readonly PoolDemand $demand, private readonly ProvisionRunner $provision) {}

    /** @return int runners started */
    public function __invoke(RunnerPool $pool): int
    {
        if ($pool->isPaused()) {
            return 0;
        }

        $started = 0;
        $wanted = min(self::MAX_PER_PASS, $this->demand->deficit($pool));

        while ($started < $wanted) {
            try {
                if (($this->provision)($pool) === null) {
                    break; // at max_runners
                }
            } catch (HostUnavailable|GithubRateLimited) {
                break; // nothing to do until capacity or the rate limit comes back
            } catch (GithubApiError $e) {
                Log::warning('pool reconcile: provisioning failed', ['pool' => $pool->id, 'error' => $e->getMessage()]);
                break;
            }
            $started++;
        }

        return $started;
    }
}
