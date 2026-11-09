<?php

declare(strict_types=1);

namespace App\Domain\Github\Data;

use App\Domain\Github\Enums\AccountType;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Enums\RepositorySelection;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Built from an `installation` / `installation_repositories` webhook payload (the whole body)
 * or from a bare installation object returned by the API.
 */
final class InstallationData extends Data
{
    /**
     * @param  array<int, RepositoryData>  $repositories  repositories listed in the event (created / added), when present
     * @param  array<int, RepositoryData>  $repositoriesRemoved  repositories removed from the installation (installation_repositories)
     */
    public function __construct(
        public int $id,
        public string $accountLogin,
        public AccountType $accountType,
        public RepositorySelection $repositorySelection,
        public ?int $appId = null,
        public ?CarbonImmutable $suspendedAt = null,
        public ?string $action = null,
        #[DataCollectionOf(RepositoryData::class)]
        public array $repositories = [],
        #[DataCollectionOf(RepositoryData::class)]
        public array $repositoriesRemoved = [],
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        /** @var array<string, mixed> $installation */
        $installation = is_array($properties['installation'] ?? null) ? $properties['installation'] : $properties;
        /** @var array<string, mixed> $account */
        $account = $installation['account'] ?? [];

        // Missing keys are left out (not set to null) so required fields fail with a clear
        // "missing property" error and optional ones fall back to their defaults.
        return array_filter([
            'id' => $installation['id'] ?? null,
            'accountLogin' => $account['login'] ?? null,
            'accountType' => AccountType::fromGithub((string) ($account['type'] ?? $installation['target_type'] ?? 'User')),
            // installation_repositories carries the authoritative selection at the top level.
            'repositorySelection' => $properties['repository_selection'] ?? $installation['repository_selection'] ?? null,
            'appId' => $installation['app_id'] ?? null,
            'suspendedAt' => $installation['suspended_at'] ?? null,
            'action' => $properties['action'] ?? null,
            'repositories' => array_map(
                fn (array $r) => RepositoryData::from($r),
                array_values(array_merge($properties['repositories'] ?? [], $properties['repositories_added'] ?? [])),
            ),
            'repositoriesRemoved' => array_map(
                fn (array $r) => RepositoryData::from($r),
                array_values($properties['repositories_removed'] ?? []),
            ),
        ], static fn ($value) => $value !== null);
    }

    public function status(): InstallationStatus
    {
        return match (true) {
            $this->action === 'deleted' => InstallationStatus::Deleted,
            $this->suspendedAt !== null || $this->action === 'suspend' => InstallationStatus::Suspended,
            default => InstallationStatus::Active,
        };
    }
}
