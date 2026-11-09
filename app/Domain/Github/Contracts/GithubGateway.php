<?php

declare(strict_types=1);

namespace App\Domain\Github\Contracts;

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

/**
 * Everything Jogger asks of GitHub. Production uses HttpGithubGateway; tests bind FakeGithubGateway.
 * Failures surface as GithubRateLimited (wait and retry later) or GithubApiError.
 */
interface GithubGateway
{
    /**
     * Single-use credential for an ephemeral runner. $labels is the runner's full label set
     * (1-100); the default runner group (1) is used unless the scope names one.
     *
     * @param  array<int, string>  $labels
     *
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function generateJitConfig(RunnerScope $scope, string $name, array $labels): JitConfig;

    /**
     * Removes a runner registration. Already-removed runners (404) are not an error.
     *
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function deleteRunner(RunnerScope $scope, int $githubRunnerId): void;

    /**
     * @return array<int, RunnerData> every runner in the scope (all pages), optionally filtered by exact name
     *
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function listRunners(RunnerScope $scope, ?string $name = null): array;

    /**
     * Jobs currently waiting for a runner in one repository (from queued and in-progress runs).
     *
     * @return array<int, WorkflowJobData>
     *
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function listQueuedJobs(Installation $installation, Repository $repository): array;

    /**
     * @return array<int, RepositoryData> repositories the installation can access (all pages)
     *
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function listInstallationRepositories(Installation $installation): array;

    /**
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function getRateLimit(Installation $installation): RateLimitData;
}
