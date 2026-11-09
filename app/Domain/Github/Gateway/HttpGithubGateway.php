<?php

declare(strict_types=1);

namespace App\Domain\Github\Gateway;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Github\Data\JitConfig;
use App\Domain\Github\Data\RateLimitData;
use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Data\RunnerData;
use App\Domain\Github\Data\RunnerScope;
use App\Domain\Github\Data\WorkflowJobData;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Talks to the GitHub REST API as an installation of our GitHub App.
 * Endpoint paths and required permissions were checked against docs.github.com (see docs/DECISIONS.md, Task 13).
 */
final class HttpGithubGateway implements GithubGateway
{
    private const PER_PAGE = 100;

    /** Safety valve against pagination loops. */
    private const MAX_PAGES = 50;

    public function __construct(
        private readonly InstallationTokenStore $tokens,
        private readonly GithubHttp $http,
        private readonly RateLimitTracker $limits,
    ) {}

    public function generateJitConfig(RunnerScope $scope, string $name, array $labels): JitConfig
    {
        $labels = array_values(array_unique(array_filter(array_map('trim', $labels), fn (string $label): bool => $label !== '')));

        if ($labels === [] || count($labels) > 100) {
            throw new InvalidArgumentException('A JIT runner needs between 1 and 100 labels.');
        }

        $response = $this->send($scope->installation, 'POST', $scope->runnersPath('/generate-jitconfig'), [
            'json' => [
                'name' => $name,
                'runner_group_id' => $scope->runnerGroupId ?? 1, // 1 = the "Default" runner group
                'labels' => $labels,
            ],
        ]);

        return JitConfig::from($response->json());
    }

    public function deleteRunner(RunnerScope $scope, int $githubRunnerId): void
    {
        try {
            $this->send($scope->installation, 'DELETE', $scope->runnersPath("/{$githubRunnerId}"));
        } catch (GithubApiError $e) {
            if (! $e->isNotFound()) {
                throw $e;
            }
            // Already gone: deleting is idempotent.
        }
    }

    public function listRunners(RunnerScope $scope, ?string $name = null): array
    {
        $items = $this->paginate($scope->installation, $scope->runnersPath(), $name !== null ? ['name' => $name] : [], 'runners');

        return array_map(fn (array $runner) => RunnerData::from($runner), $items);
    }

    public function listQueuedJobs(Installation $installation, Repository $repository): array
    {
        $this->assertRepoName($repository->full_name);
        $jobs = [];

        // A run with some jobs still waiting is "queued" (nothing started) or "in_progress" (a matrix with free slots).
        foreach (['queued', 'in_progress'] as $runStatus) {
            $runs = $this->send($installation, 'GET', "/repos/{$repository->full_name}/actions/runs", [
                'query' => ['status' => $runStatus, 'per_page' => self::PER_PAGE],
            ])->json('workflow_runs') ?? [];

            foreach ($runs as $run) {
                $runJobs = $this->paginate($installation, "/repos/{$repository->full_name}/actions/runs/{$run['id']}/jobs", ['filter' => 'latest'], 'jobs');

                foreach ($runJobs as $job) {
                    if (($job['status'] ?? null) !== 'queued') {
                        continue;
                    }

                    $jobs[$job['id']] = WorkflowJobData::from([
                        'action' => 'queued',
                        'workflow_job' => $job,
                        'repository' => ['id' => $repository->github_repo_id, 'full_name' => $repository->full_name, 'private' => $repository->private],
                        'installation' => ['id' => $installation->github_installation_id],
                    ]);
                }
            }
        }

        return array_values($jobs);
    }

    public function listInstallationRepositories(Installation $installation): array
    {
        $items = $this->paginate($installation, '/installation/repositories', [], 'repositories');

        return array_map(fn (array $repo) => RepositoryData::from($repo), $items);
    }

    public function getRateLimit(Installation $installation): RateLimitData
    {
        return RateLimitData::from($this->send($installation, 'GET', '/rate_limit')->json());
    }

    /**
     * Sends a request as the installation. A 401 means the cached token was revoked or expired early,
     * so the token is replaced and the request retried once.
     *
     * @param  array<string, mixed>  $options  Guzzle-style: json, query
     *
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    private function send(Installation $installation, string $method, string $path, array $options = [], bool $retryUnauthorized = true): Response
    {
        $installationId = $installation->github_installation_id;

        if (($until = $this->limits->pausedUntil($installationId)) !== null) {
            throw new GithubRateLimited('GitHub API rate limit reached.', max(1, (int) now()->diffInSeconds($until)));
        }

        try {
            $response = $this->http->pending($installation->app, $this->tokens->get($installation))->send($method, $path, $options);
        } catch (ConnectionException $e) {
            throw $this->http->unreachable($e);
        }

        $this->limits->record($installationId, $response);

        if ($response->status() === 401 && $retryUnauthorized) {
            $this->tokens->forget($installation);

            return $this->send($installation, $method, $path, $options, retryUnauthorized: false);
        }

        if ($response->successful()) {
            return $response;
        }

        if (($seconds = $this->http->rateLimitSeconds($response)) !== null) {
            $this->limits->pause($installationId, $seconds);
        }

        $this->http->failure($response);
    }

    /**
     * Follows `page` until a short page (or the safety cap) and returns the merged items under $key.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function paginate(Installation $installation, string $path, array $query, string $key): array
    {
        $all = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->send($installation, 'GET', $path, ['query' => $query + ['per_page' => self::PER_PAGE, 'page' => $page]])->json();
            $items = $body[$key] ?? [];
            array_push($all, ...$items);

            $total = $body['total_count'] ?? null;
            if (count($items) < self::PER_PAGE || ($total !== null && count($all) >= $total)) {
                break;
            }
        }

        return $all;
    }

    private function assertRepoName(string $fullName): void
    {
        if (preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $fullName) !== 1) {
            throw new InvalidArgumentException("Invalid GitHub repository name: {$fullName}");
        }
    }
}
