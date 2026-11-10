<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Actions\TransitionRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Support\Exceptions\GithubRateLimited;

/**
 * Compares GitHub's list of self-hosted runners with Jogger's, per pool scope:
 *  - a `jogger-…` runner on GitHub that Jogger does not know, or knows as finished, is deleted there;
 *  - a Jogger runner (idle or busy) that GitHub has not listed for five minutes is failed and its container stopped.
 */
final class ReconcileRunners
{
    public const MISSING_AFTER_SECONDS = 300;

    public function __construct(
        private readonly GithubGateway $github,
        private readonly TransitionRunner $transition,
        private readonly HostDriver $driver,
    ) {}

    /**
     * @return array{deleted: int, failed: int}
     *
     * @throws GithubRateLimited
     */
    public function __invoke(): array
    {
        $deleted = 0;
        $failed = 0;

        // Several pools can share one scope (same organization, different labels): list each scope once.
        $scopes = RunnerPool::query()->with('installation')->get()
            ->filter(fn (RunnerPool $pool) => $pool->installation->isActive())
            ->groupBy(fn (RunnerPool $pool) => "{$pool->installation_id}|{$pool->scope->value}|".mb_strtolower($pool->scope_target));

        foreach ($scopes as $pools) {
            $scope = $pools->first()->githubScope();
            $listed = collect($this->github->listRunners($scope))->filter(fn ($r) => str_starts_with($r->name, Runner::NAME_PREFIX));
            $ours = Runner::query()->whereIn('pool_id', $pools->modelKeys())->whereIn('name', $listed->pluck('name'))->get()->keyBy('name');

            foreach ($listed as $remote) {
                $local = $ours->get($remote->name);
                if ($local === null || $local->state->isTerminal()) {
                    $this->github->deleteRunner($scope, $remote->id);
                    $deleted++;
                }
            }

            $missing = Runner::query()->whereIn('pool_id', $pools->modelKeys())
                ->inState(RunnerState::Idle, RunnerState::Busy)
                ->whereNotNull('github_runner_id')
                ->whereNotIn('name', $listed->pluck('name'))
                ->where('state_changed_at', '<', now()->subSeconds(self::MISSING_AFTER_SECONDS))
                ->get();

            foreach ($missing as $runner) {
                if (! ($this->transition)($runner, RunnerState::Failed, ['exit_reason' => 'github_missing'])) {
                    continue;
                }
                $failed++;
                if ($runner->host !== null && $runner->host->status !== HostStatus::Revoked) {
                    $this->driver->send($runner->host, 'runner.stop', ['runner_id' => $runner->id]);
                }
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
