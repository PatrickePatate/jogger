<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use Illuminate\Support\Str;

/** Bulk, atomic create-or-update of repositories (GitHub's repository id is the identity; a moved repo changes installation). */
final class UpsertRepositories
{
    private const CHUNK = 500;

    /**
     * @param  array<int, RepositoryData>  $repositories
     */
    public function __invoke(Installation $installation, array $repositories): void
    {
        $now = now();

        $rows = array_map(fn (RepositoryData $repo) => [
            'id' => (string) Str::uuid7(),
            'installation_id' => $installation->id,
            'github_repo_id' => $repo->id,
            'full_name' => $repo->fullName,
            'private' => $repo->private,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_column(array_map(fn ($r) => ['k' => $r->id, 'r' => $r], $repositories), 'r', 'k'))); // de-duplicate by id

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            Repository::query()->upsert($chunk, ['github_repo_id'], ['installation_id', 'full_name', 'private', 'updated_at']);
        }
    }
}
