<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Alerts;

use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Gateway\RateLimitTracker;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;

/** Reads the current state and lists every alert condition that holds. Purely a reader: it changes nothing. */
final class DetectConditions
{
    /** Runner endings that mean "could not even start", as opposed to a job failing. */
    private const array PROVISION_FAILURES = ['jit_failed', 'dispatch_failed', 'start_failed', 'provision_timeout'];

    public function __construct(private readonly RateLimitTracker $github) {}

    /**
     * @return list<Condition>
     */
    public function __invoke(): array
    {
        return [...$this->hosts(), ...$this->pools(), ...$this->github(), ...$this->webhooks()];
    }

    /**
     * @return list<Condition>
     */
    private function hosts(): array
    {
        $conditions = [];
        $floor = (int) config('jogger.alerts.disk_free_gb');
        foreach (Host::query()->whereIn('status', [HostStatus::Offline->value, HostStatus::Online->value, HostStatus::Cordoned->value, HostStatus::Draining->value])->get() as $host) {
            if ($host->status === HostStatus::Offline) {
                $seen = $host->last_seen_at?->toDayDateTimeString() ?? 'never';
                $conditions[] = new Condition(AlertType::HostOffline, $host->id, "Host {$host->name} is offline",
                    "No heartbeat since {$seen}. Its runners are treated as lost and no new ones start there.");
            } elseif ($host->disk_free_gb !== null && $host->disk_free_gb < $floor) {
                $conditions[] = new Condition(AlertType::DiskPressure, $host->id, "Host {$host->name} is low on disk space",
                    "{$host->disk_free_gb} GB free, below the {$floor} GB threshold. Image pulls and jobs may start failing.");
            }
        }

        return $conditions;
    }

    /**
     * @return list<Condition>
     */
    private function pools(): array
    {
        $threshold = max(1, (int) config('jogger.alerts.provision_failures'));
        $since = now()->subMinutes(10);

        $failing = Runner::query()->where('state', RunnerState::Failed->value)->whereIn('exit_reason', self::PROVISION_FAILURES)
            ->where('finished_at', '>=', $since)->groupBy('pool_id')->toBase()
            ->selectRaw('pool_id, count(*) as n, max(finished_at) as last')->havingRaw('count(*) >= ?', [$threshold])->get()->keyBy('pool_id');
        if ($failing->isEmpty()) {
            return [];
        }
        // A runner that came up after the last failure means the pool recovered.
        $recovered = Runner::query()->whereIn('pool_id', $failing->keys())->whereNotNull('started_at')->where('started_at', '>=', $since)
            ->groupBy('pool_id')->toBase()->selectRaw('pool_id, max(started_at) as last')->pluck('last', 'pool_id');

        $conditions = [];
        foreach (RunnerPool::query()->whereIn('id', $failing->keys())->get() as $pool) {
            if (isset($recovered[$pool->id]) && $recovered[$pool->id] > $failing[$pool->id]->last) {
                continue;
            }
            $conditions[] = new Condition(AlertType::PoolCannotProvision, $pool->id, "Pool {$pool->name} cannot start runners",
                "{$failing[$pool->id]->n} runners failed to start in the last 10 minutes. Jobs for this pool stay queued.");
        }

        return $conditions;
    }

    /**
     * @return list<Condition>
     */
    private function github(): array
    {
        $conditions = [];
        foreach (Installation::query()->where('status', InstallationStatus::Active->value)->get() as $installation) {
            if ($this->github->unauthorized((int) $installation->github_installation_id)) {
                $conditions[] = new Condition(AlertType::GithubAuthFailure, $installation->id, "GitHub rejects Jogger's credentials for {$installation->account_login}",
                    'Check the GitHub App private key, that the app is still installed, and the server clock. Runners cannot be registered until this is fixed.');
            }
        }

        return $conditions;
    }

    /**
     * @return list<Condition>
     */
    private function webhooks(): array
    {
        $failed = WebhookDelivery::query()->where('status', DeliveryStatus::Failed->value)->where('received_at', '>=', now()->subMinutes(15))->count();

        return $failed >= max(1, (int) config('jogger.alerts.webhook_failures'))
            ? [new Condition(AlertType::WebhookFailures, 'instance', 'GitHub webhooks are failing to process',
                "{$failed} deliveries failed in the last 15 minutes. Jobs may not be picked up until the cause is fixed; see the Deliveries page.")]
            : [];
    }
}
