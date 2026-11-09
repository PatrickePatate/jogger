<?php

declare(strict_types=1);

namespace App\Domain\Github\Data;

use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Models\Installation;
use InvalidArgumentException;

/** Where a runner lives on GitHub; runner pools (Task 29) build one of these. */
final readonly class RunnerScope
{
    public function __construct(
        public Installation $installation,
        public ScopeType $type,
        /** "owner/repo" for repositories, the login for organizations. */
        public string $target,
        public ?int $runnerGroupId = null,
    ) {
        $valid = $type === ScopeType::Repository
            ? preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $target)
            : preg_match('#^[A-Za-z0-9._-]+$#', $target);

        if ($valid !== 1) {
            throw new InvalidArgumentException("Invalid GitHub {$type->value} name: {$target}");
        }
    }

    /** API path of the self-hosted runners resource, e.g. "/repos/acme/api/actions/runners/generate-jitconfig". */
    public function runnersPath(string $suffix = ''): string
    {
        $base = $this->type === ScopeType::Repository ? "/repos/{$this->target}" : "/orgs/{$this->target}";

        return "{$base}/actions/runners{$suffix}";
    }
}
