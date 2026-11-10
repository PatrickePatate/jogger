<?php

declare(strict_types=1);

namespace App\Domain\Pools\Actions;

use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Models\Repository;
use App\Domain\Pools\Data\PoolMatch;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Models\Runner;

/**
 * Finds the pool for a job. A pool matches when it serves the job's repository (directly or through its
 * organization) and its labels contain every label the job asked for, compared case-insensitively like GitHub does.
 * Among matches the most specific wins (fewest labels beyond the job's), then the least used.
 */
final class MatchPool
{
    /**
     * @param  list<string>  $labels
     */
    public function __invoke(Repository $repository, array $labels): PoolMatch
    {
        $wanted = array_values(array_unique(array_map('mb_strtolower', $labels)));

        $candidates = RunnerPool::query()
            ->where('installation_id', $repository->installation_id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('scope', ScopeType::Repository->value)->whereRaw('lower(scope_target) = ?', [mb_strtolower($repository->full_name)]))
                ->orWhere(fn ($q) => $q->where('scope', ScopeType::Organization->value)->whereRaw('lower(scope_target) = ?', [mb_strtolower($repository->owner())])))
            ->get()
            ->filter(fn (RunnerPool $pool) => $wanted !== [] && array_diff($wanted, array_map('mb_strtolower', $pool->labels)) === []);

        if ($candidates->isEmpty()) {
            return new PoolMatch(null, PoolMatch::NO_MATCHING_POOL, $labels);
        }

        $active = $candidates->reject(fn (RunnerPool $pool) => $pool->isPaused());
        if ($active->isEmpty()) {
            return new PoolMatch(null, PoolMatch::POOL_PAUSED, $labels);
        }

        $live = Runner::query()->live()->whereIn('pool_id', $active->modelKeys())
            ->selectRaw('pool_id, count(*) as n')->groupBy('pool_id')->toBase()->pluck('n', 'pool_id');

        $best = $active->sort(fn (RunnerPool $a, RunnerPool $b) => [count($a->labels), (int) ($live[$a->id] ?? 0) / $a->max_runners, $a->name]
            <=> [count($b->labels), (int) ($live[$b->id] ?? 0) / $b->max_runners, $b->name])->first();

        return new PoolMatch($best);
    }

    /**
     * Whether a job with no matching pool is still ours to report: only when it explicitly asks for a self-hosted
     * runner. Jobs for GitHub-hosted runners (ubuntu-latest, …) are none of Jogger's business.
     *
     * @param  list<string>  $labels
     */
    public static function wantsSelfHosted(array $labels): bool
    {
        return in_array('self-hosted', array_map('mb_strtolower', $labels), true);
    }
}
