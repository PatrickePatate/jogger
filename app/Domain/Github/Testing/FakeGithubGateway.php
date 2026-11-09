<?php

declare(strict_types=1);

namespace App\Domain\Github\Testing;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Github\Data\JitConfig;
use App\Domain\Github\Data\RateLimitData;
use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Data\RunnerData;
use App\Domain\Github\Data\RunnerScope;
use App\Domain\Github\Data\WorkflowJobData;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * In-memory GithubGateway for tests: records calls, hands out predictable JIT configs, and can be told to fail.
 * Bind it with FakeGithubGateway::install().
 */
final class FakeGithubGateway implements GithubGateway
{
    /** @var array<int, array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<int, RunnerData> runners that "exist" on GitHub, keyed by id */
    public array $runners = [];

    /** @var array<int, WorkflowJobData> */
    public array $queuedJobs = [];

    /** @var array<int, RepositoryData> */
    public array $repositories = [];

    public ?RateLimitData $rateLimit = null;

    private int $nextRunnerId = 1000;

    private ?Throwable $nextFailure = null;

    /** Binds a fresh fake into the container and returns it. */
    public static function install(): self
    {
        $fake = new self;
        app()->instance(GithubGateway::class, $fake);

        return $fake;
    }

    /** Make the next gateway call throw (once). */
    public function failNextWith(Throwable $e): self
    {
        $this->nextFailure = $e;

        return $this;
    }

    public function generateJitConfig(RunnerScope $scope, string $name, array $labels): JitConfig
    {
        $this->record('generateJitConfig', ['scope' => $scope, 'name' => $name, 'labels' => $labels]);

        $id = $this->nextRunnerId++;
        $this->runners[$id] = new RunnerData($id, $name, 'offline', false, 'linux', $labels);

        return new JitConfig($id, $name, "fake-jit-config-{$id}", $labels);
    }

    public function deleteRunner(RunnerScope $scope, int $githubRunnerId): void
    {
        $this->record('deleteRunner', ['scope' => $scope, 'runnerId' => $githubRunnerId]);

        unset($this->runners[$githubRunnerId]);
    }

    public function listRunners(RunnerScope $scope, ?string $name = null): array
    {
        $this->record('listRunners', ['scope' => $scope, 'name' => $name]);

        return array_values(array_filter($this->runners, fn (RunnerData $r) => $name === null || $r->name === $name));
    }

    public function listQueuedJobs(Installation $installation, Repository $repository): array
    {
        $this->record('listQueuedJobs', ['installation' => $installation, 'repository' => $repository]);

        return array_values(array_filter($this->queuedJobs, fn (WorkflowJobData $j) => $j->repositoryFullName === $repository->full_name));
    }

    public function listInstallationRepositories(Installation $installation): array
    {
        $this->record('listInstallationRepositories', ['installation' => $installation]);

        return $this->repositories;
    }

    public function getRateLimit(Installation $installation): RateLimitData
    {
        $this->record('getRateLimit', ['installation' => $installation]);

        return $this->rateLimit ?? new RateLimitData(5000, 4999, 1, CarbonImmutable::now()->addHour());
    }

    /** @return array<int, array<string, mixed>> arguments of every call to $method */
    public function callsTo(string $method): array
    {
        return array_values(array_map(fn ($c) => $c['args'], array_filter($this->calls, fn ($c) => $c['method'] === $method)));
    }

    public function assertCalled(string $method, int $times = 1): void
    {
        Assert::assertCount($times, $this->callsTo($method), "Expected {$times} call(s) to {$method}.");
    }

    public function assertNotCalled(string $method): void
    {
        Assert::assertSame([], $this->callsTo($method), "Did not expect a call to {$method}.");
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }
    }
}
