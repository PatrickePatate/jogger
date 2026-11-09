<?php

declare(strict_types=1);

namespace App\Domain\Github\Handlers;

use App\Domain\Github\Actions\SyncInstallation;
use App\Domain\Github\Actions\UpsertInstallation;
use App\Domain\Github\Actions\UpsertRepositories;
use App\Domain\Github\Contracts\WebhookHandler;
use App\Domain\Github\Data\InstallationData;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Enums\RepositorySelection;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;
use App\Support\Exceptions\GithubApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `installation` events: created, deleted, suspend, unsuspend, new_permissions_accepted. The lifecycle state is
 * derived from the payload (a `deleted` action, otherwise whether `suspended_at` is set), so an action name we
 * do not know still ends in the right state. Idempotent: every path is an upsert or a "set to".
 */
final class HandleInstallation implements WebhookHandler
{
    public function __construct(
        private readonly UpsertInstallation $upsertInstallation,
        private readonly UpsertRepositories $upsertRepositories,
        private readonly SyncInstallation $sync,
    ) {}

    public function handle(WebhookDelivery $delivery): void
    {
        $app = $delivery->app;

        if ($app === null) {
            return; // the app was removed from Jogger after this delivery arrived
        }

        $data = InstallationData::from($delivery->payload);

        if ($data->status() === InstallationStatus::Deleted) {
            $this->deleted($data);

            return;
        }

        $installation = DB::transaction(function () use ($app, $data): Installation {
            $installation = ($this->upsertInstallation)($app, $data, $data->status());
            ($this->upsertRepositories)($installation, $data->repositories);

            return $installation;
        });

        // For "all repositories" the payload may not list everything, so ask GitHub; the same after an unsuspend,
        // when repositories may have changed in the meantime.
        if ($installation->isActive() && ($data->repositorySelection === RepositorySelection::All || $data->action === 'unsuspend')) {
            $this->tryToSync($installation);
        }
    }

    private function deleted(InstallationData $data): void
    {
        $installation = Installation::query()->where('github_installation_id', $data->id)->first();

        if ($installation === null) {
            return; // never knew it; nothing to clean up
        }

        DB::transaction(function () use ($installation): void {
            $installation->forceFill(['status' => InstallationStatus::Deleted])->save();
            // GitHub gives a re-installation a new installation id, so the old rows would only be stale.
            Repository::query()->where('installation_id', $installation->id)->delete();
        });
    }

    /** A failed sync must not fail the webhook: the payload data is already stored and Resync is one click. A rate limit is retried by the queue. */
    private function tryToSync(Installation $installation): void
    {
        try {
            ($this->sync)($installation);
        } catch (GithubApiError $e) {
            Log::warning('github.installation_sync_failed', ['installation' => $installation->github_installation_id, 'error' => $e->getMessage()]);
        }
    }
}
