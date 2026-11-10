<?php

declare(strict_types=1);

namespace App\Domain\Runners\Actions;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Hosts\Actions\PickHost;
use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Domain\Secrets\Contracts\ResolvesSecrets;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use App\Support\Exceptions\HostUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates one runner for a pool, in three steps that each leave a consistent state behind:
 *   1. reserve — under a lock on the pool row, check the ceiling, pick a host, insert the runner as `provisioning`;
 *   2. register — ask GitHub for a single-use JIT config (outside the transaction: it is a network call);
 *   3. start — hand the host a `runner.start` command through the outbox.
 * A failure after step 1 marks the runner `failed`, which frees its slot.
 */
final class ProvisionRunner
{
    public function __construct(
        private readonly PickHost $pickHost,
        private readonly GithubGateway $github,
        private readonly HostDriver $driver,
        private readonly TransitionRunner $transition,
        private readonly ResolvesSecrets $secrets,
    ) {}

    /**
     * @return Runner|null null when the pool is paused or already at max_runners (GitHub is not called)
     *
     * @throws HostUnavailable no host can take a runner of this pool right now
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function __invoke(RunnerPool $pool): ?Runner
    {
        $runner = $this->reserve($pool);
        if ($runner === null) {
            return null;
        }

        try {
            $jit = $this->github->generateJitConfig($pool->githubScope(), $runner->name, $pool->labels);
            $runner->forceFill(['github_runner_id' => $jit->runnerId])->save();

            $this->driver->send($runner->host, 'runner.start', [
                'runner_id' => $runner->id,
                'name' => $runner->name,
                'image' => $pool->image,
                'jit_config' => $jit->encodedJitConfig,
                'cpu' => $pool->cpu_limit,
                'memory_mb' => $pool->memory_limit_mb,
                'env' => (object) $this->secrets->envFor($pool),
                'labels' => $pool->labels,
                'docker_mode' => $pool->docker_mode->value,
                ...(($auth = $this->secrets->registryAuthFor($pool)) !== null ? ['registry_auth' => $auth] : []),
            ]);
        } catch (Throwable $e) {
            ($this->transition)($runner, RunnerState::Failed, ['exit_reason' => $runner->github_runner_id === null ? 'jit_failed' : 'dispatch_failed']);

            throw $e;
        }

        return $runner;
    }

    private function reserve(RunnerPool $pool): ?Runner
    {
        return DB::transaction(function () use ($pool) {
            // Serializes every provisioning of this pool, so the count below cannot be raced past max_runners.
            $locked = RunnerPool::query()->whereKey($pool->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isPaused()) {
                return null;
            }
            if (Runner::query()->where('pool_id', $locked->id)->live()->count() >= $locked->max_runners) {
                return null;
            }

            $host = ($this->pickHost)($locked);
            if ($host === null) {
                throw new HostUnavailable;
            }

            return Runner::query()->create([
                'pool_id' => $locked->id,
                'host_id' => $host->id,
                'name' => Runner::NAME_PREFIX.Str::limit(Str::slug($locked->name), 40, '').'-'.Str::lower(Str::random(10)),
                'state' => RunnerState::Provisioning,
                'state_changed_at' => now(),
            ]);
        });
    }
}
