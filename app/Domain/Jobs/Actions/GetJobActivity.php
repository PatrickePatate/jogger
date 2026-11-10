<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** How many CI jobs were queued per day, limited to the repositories the user may see. */
final class GetJobActivity
{
    /**
     * @return array{days: array<string, int>, failed: array<string, int>, total: int}
     */
    public function __invoke(User $user, int $weeks = 26): array
    {
        $since = now()->startOfDay()->subWeeks($weeks);

        $rows = WorkflowJob::query()
            ->visibleTo($user)
            ->where('queued_at', '>=', $since)
            ->selectRaw('queued_at::date as day, count(*) as total')
            ->selectRaw('count(*) filter (where conclusion in (?, ?)) as failed', [JobConclusion::Failure->value, JobConclusion::TimedOut->value])
            ->groupBy(DB::raw('queued_at::date'))
            ->get();

        $days = [];
        $failed = [];
        foreach ($rows as $row) {
            $days[(string) $row->getAttribute('day')] = (int) $row->getAttribute('total');
            $failed[(string) $row->getAttribute('day')] = (int) $row->getAttribute('failed');
        }

        return ['days' => $days, 'failed' => $failed, 'total' => array_sum($days)];
    }
}
