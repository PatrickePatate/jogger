<?php

declare(strict_types=1);

namespace App\Domain\Github\Handlers;

use App\Domain\Github\Actions\UpsertRepositories;
use App\Domain\Github\Contracts\WebhookHandler;
use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Enums\RepositorySelection;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;

/**
 * `repository` events keep the repositories we already know accurate: renamed/transferred/edited update the name,
 * publicized/privatized update the visibility, deleted removes it. Only `created` can add one, and only when the
 * installation covers "all repositories" (for "selected" access GitHub sends installation_repositories instead).
 * Unknown actions just refresh whatever the payload says, so a new GitHub action name cannot break this.
 */
final class HandleRepository implements WebhookHandler
{
    public function __construct(private readonly UpsertRepositories $upsertRepositories) {}

    public function handle(WebhookDelivery $delivery): void
    {
        $payload = $delivery->payload;
        $repo = RepositoryData::from($payload['repository'] ?? []);
        $action = (string) ($payload['action'] ?? '');

        if ($action === 'deleted') {
            Repository::query()->where('github_repo_id', $repo->id)->delete();

            return;
        }

        if ($action === 'created') {
            $installationId = $payload['installation']['id'] ?? null;
            $installation = $installationId ? Installation::query()->where('github_installation_id', $installationId)->first() : null;

            if ($installation !== null && $installation->isActive() && $installation->repository_selection === RepositorySelection::All) {
                ($this->upsertRepositories)($installation, [$repo]);
            }

            return;
        }

        Repository::query()->where('github_repo_id', $repo->id)->update([
            'full_name' => $repo->fullName,
            'private' => $repo->private,
            'updated_at' => now(),
        ]);
    }
}
