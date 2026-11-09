<?php

declare(strict_types=1);

namespace App\Domain\Github\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * A `workflow_job` webhook, flattened to what Jogger needs. Build it from the whole webhook body:
 * WorkflowJobData::from($payload). Fields GitHub adds later are ignored.
 */
final class WorkflowJobData extends Data
{
    /**
     * @param  array<int, string>  $labels  the job's runs-on labels
     */
    public function __construct(
        public string $action,
        public int $jobId,
        public int $runId,
        public int $runAttempt,
        public string $name,
        public ?string $workflowName,
        public string $headSha,
        public ?string $headBranch,
        public string $status,
        public ?string $conclusion,
        public array $labels,
        /** API URL of the workflow run. */
        public string $runUrl,
        /** Web URL of the job page on github.com. */
        public string $htmlUrl,
        public int $repositoryId,
        public string $repositoryFullName,
        public bool $repositoryPrivate,
        public ?int $installationId = null,
        public ?string $actor = null,
        public ?int $runnerId = null,
        public ?string $runnerName = null,
        public ?int $runnerGroupId = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $completedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        /** @var array<string, mixed> $job */
        $job = $properties['workflow_job'] ?? [];
        /** @var array<string, mixed> $repo */
        $repo = $properties['repository'] ?? [];

        // Missing keys are left out (not set to null) so required fields fail with a clear
        // "missing property" error and optional ones fall back to their defaults.
        return array_filter([
            'action' => $properties['action'] ?? null,
            'jobId' => $job['id'] ?? null,
            'runId' => $job['run_id'] ?? null,
            'runAttempt' => $job['run_attempt'] ?? 1,
            'name' => $job['name'] ?? null,
            'workflowName' => $job['workflow_name'] ?? null,
            'headSha' => $job['head_sha'] ?? null,
            'headBranch' => $job['head_branch'] ?? null,
            'status' => $job['status'] ?? null,
            'conclusion' => $job['conclusion'] ?? null,
            'labels' => array_values(array_map('strval', $job['labels'] ?? [])),
            'runUrl' => $job['run_url'] ?? null,
            'htmlUrl' => $job['html_url'] ?? null,
            'repositoryId' => $repo['id'] ?? null,
            'repositoryFullName' => $repo['full_name'] ?? null,
            'repositoryPrivate' => $repo['private'] ?? true,
            'installationId' => $properties['installation']['id'] ?? null,
            'actor' => $properties['sender']['login'] ?? null,
            'runnerId' => $job['runner_id'] ?? null,
            'runnerName' => $job['runner_name'] ?? null,
            'runnerGroupId' => $job['runner_group_id'] ?? null,
            'createdAt' => $job['created_at'] ?? null,
            'startedAt' => $job['started_at'] ?? null,
            'completedAt' => $job['completed_at'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /** Jobs that target GitHub-hosted runners (no `self-hosted` label) are none of Jogger's business. */
    public function isSelfHosted(): bool
    {
        return in_array('self-hosted', array_map('strtolower', $this->labels), true);
    }

    /**
     * True when every label the job asks for is in the given set (pool matching, Task 32).
     *
     * @param  array<int, string>  $poolLabels
     */
    public function labelsCoveredBy(array $poolLabels): bool
    {
        $have = array_map('strtolower', $poolLabels);

        foreach ($this->labels as $label) {
            if (! in_array(strtolower($label), $have, true)) {
                return false;
            }
        }

        return true;
    }
}
