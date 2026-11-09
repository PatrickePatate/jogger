<?php

declare(strict_types=1);

namespace App\Domain\Github\Handlers;

use App\Domain\Github\Actions\UpsertInstallation;
use App\Domain\Github\Actions\UpsertRepositories;
use App\Domain\Github\Contracts\WebhookHandler;
use App\Domain\Github\Data\InstallationData;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

/**
 * `installation_repositories` (added / removed): applies `repositories_added` and `repositories_removed` and the new
 * `repository_selection` in one transaction. If the installation is unknown (its `created` event was missed or is still
 * queued) it is created from this payload, so event order does not matter.
 */
final class HandleInstallationRepositories implements WebhookHandler
{
    public function __construct(private readonly UpsertInstallation $upsertInstallation, private readonly UpsertRepositories $upsertRepositories) {}

    public function handle(WebhookDelivery $delivery): void
    {
        $app = $delivery->app;

        if ($app === null) {
            return;
        }

        $data = InstallationData::from($delivery->payload);

        DB::transaction(function () use ($app, $data): void {
            // Status is left alone: only installation events know the lifecycle state.
            $installation = ($this->upsertInstallation)($app, $data);

            ($this->upsertRepositories)($installation, $data->repositories);

            if ($data->repositoriesRemoved !== []) {
                Repository::query()
                    ->where('installation_id', $installation->id)
                    ->whereIn('github_repo_id', array_map(fn ($r) => $r->id, $data->repositoriesRemoved))
                    ->delete();
            }
        });
    }
}
