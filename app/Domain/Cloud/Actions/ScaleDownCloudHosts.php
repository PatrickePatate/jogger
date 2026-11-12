<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Access\Audit;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Carbon\Carbon;

/**
 * Removes cloud servers that are no longer needed. In order:
 *   1. an online host past its template's max lifetime (from billed_from) goes to draining, so PickHost skips it;
 *   2. a draining host with no present runner is deleted, whatever its idle time, warm count or billing hour;
 *   3. an online host is deleted once it has been idle longer than its template's idle_seconds, oldest first, while
 *      the template keeps more than warm_hosts open hosts, and — for hourly billing — only in the last five minutes
 *      of its paid hour (an idle host that is already paid for stays available until then);
 *   4. an online host whose template was disabled or deleted is deleted once it is idle.
 * Every deletion goes through DeleteCloudHost.
 */
final class ScaleDownCloudHosts
{
    /** Idle time for a host whose template no longer exists, since there is no setting to read. */
    private const ORPHANED_IDLE_SECONDS = 300;

    /** Seconds into a paid hour from which a host may be deleted: the last five minutes. */
    private const BILLING_WINDOW_START = 3300;

    public function __construct(
        private readonly DeleteCloudHost $delete,
        private readonly TransitionCloudHost $transition,
    ) {}

    /** @return int hosts deleted */
    public function __invoke(): int
    {
        $this->drainExpired();

        return $this->deleteEmptyDraining() + $this->deleteIdle() + $this->deleteRetiredTemplates();
    }

    private function drainExpired(): void
    {
        Host::query()
            ->with('template')
            ->where('cloud_state', CloudHostState::Online->value)
            ->whereNotNull('host_template_id')
            ->get()
            ->each(function (Host $host) {
                $template = $host->template;
                if ($template === null || $this->ageSeconds($host) < $template->max_lifetime_seconds) {
                    return;
                }

                if (($this->transition)($host, CloudHostState::Draining)) {
                    Audit::record('cloud.host_draining', $host, ['name' => $host->name, 'reason' => 'max_lifetime']);
                }
            });
    }

    private function deleteEmptyDraining(): int
    {
        $deleted = 0;

        Host::query()->where('cloud_state', CloudHostState::Draining->value)->get()
            ->each(function (Host $host) use (&$deleted) {
                if ($this->hasPresentRunner($host)) {
                    return;
                }
                if (($this->delete)($host, 'drained')) {
                    $deleted++;
                }
            });

        return $deleted;
    }

    private function deleteIdle(): int
    {
        $deleted = 0;

        HostTemplate::query()->where('status', 'active')->get()->each(function (HostTemplate $template) use (&$deleted) {
            $idle = Host::query()
                ->where('host_template_id', $template->id)
                ->where('cloud_state', CloudHostState::Online->value)
                ->get()
                ->filter(fn (Host $host) => $this->isIdleLongEnough($host, $template) && $this->inBillingWindow($host, $template))
                ->sortBy(fn (Host $host) => $this->startedAt($host)->timestamp);

            $open = Host::query()
                ->where('host_template_id', $template->id)
                ->whereIn('cloud_state', CloudHostState::open())
                ->count();

            foreach ($idle as $host) {
                if ($open - 1 < (int) $template->warm_hosts) {
                    break;
                }

                // Either way the host leaves the open states (deleted, or failed for the reconciler to retry).
                $open--;
                if (($this->delete)($host, 'idle')) {
                    $deleted++;
                }
            }
        });

        return $deleted;
    }

    /** Hosts whose template was disabled or deleted: no warm minimum or billing rule applies to them any more. */
    private function deleteRetiredTemplates(): int
    {
        $deleted = 0;

        Host::query()
            ->with('template')
            ->where('cloud_state', CloudHostState::Online->value)
            ->where(fn ($query) => $query
                ->whereNull('host_template_id')
                ->orWhereHas('template', fn ($template) => $template->where('status', '!=', 'active')))
            ->get()
            ->each(function (Host $host) use (&$deleted) {
                $template = $host->template;
                $idleLimit = $template === null ? self::ORPHANED_IDLE_SECONDS : $template->idle_seconds;
                $idle = $this->idleSeconds($host);

                if ($idle === null || $idle <= $idleLimit) {
                    return;
                }
                if (($this->delete)($host, 'template_retired')) {
                    $deleted++;
                }
            });

        return $deleted;
    }

    private function isIdleLongEnough(Host $host, HostTemplate $template): bool
    {
        $idle = $this->idleSeconds($host);

        return $idle !== null && $idle > $template->idle_seconds;
    }

    private function hasPresentRunner(Host $host): bool
    {
        return Runner::query()->where('host_id', $host->id)->inState(...RunnerState::present())->exists();
    }

    /**
     * Seconds since the host last had a runner end, or since it came online when it never had one. Null while a
     * runner on it is still present.
     */
    private function idleSeconds(Host $host): ?int
    {
        if ($this->hasPresentRunner($host)) {
            return null;
        }

        $lastEnded = Runner::query()
            ->where('host_id', $host->id)
            ->selectRaw('max(coalesce(finished_at, state_changed_at)) as last_ended')
            ->value('last_ended');

        $since = $lastEnded !== null ? Carbon::parse($lastEnded) : $host->cloud_state_changed_at;
        if ($since === null) {
            return null;
        }

        return now()->timestamp - $since->timestamp;
    }

    private function inBillingWindow(Host $host, HostTemplate $template): bool
    {
        if (! $template->hourly_billing) {
            return true;
        }

        return $this->ageSeconds($host) % 3600 >= self::BILLING_WINDOW_START;
    }

    private function ageSeconds(Host $host): int
    {
        return now()->timestamp - $this->startedAt($host)->timestamp;
    }

    /** When billing began for the host: its boot, or its creation for a row that never recorded it. */
    private function startedAt(Host $host): Carbon
    {
        return $host->billed_from ?? $host->created_at;
    }
}
