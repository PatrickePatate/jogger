<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Github\Data\InstallationData;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use Illuminate\Support\Str;

/**
 * Creates or updates the installation row from GitHub's description of it. Atomic (INSERT … ON CONFLICT), so two
 * webhooks about the same installation arriving together cannot collide.
 */
final class UpsertInstallation
{
    /**
     * @param  InstallationStatus|null  $status  null keeps the stored status (or "active" for a new row); only
     *                                           installation events know the real lifecycle state
     */
    public function __invoke(GithubApp $app, InstallationData $data, ?InstallationStatus $status = null): Installation
    {
        $now = now();

        $update = ['account_login', 'account_type', 'repository_selection', 'updated_at'];
        if ($status !== null) {
            $update[] = 'status';
        }

        Installation::query()->upsert([[
            'id' => (string) Str::uuid7(),
            'github_app_id' => $app->id,
            'github_installation_id' => $data->id,
            'account_login' => $data->accountLogin,
            'account_type' => $data->accountType->value,
            'status' => ($status ?? InstallationStatus::Active)->value,
            'repository_selection' => $data->repositorySelection->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['github_installation_id'], $update);

        return Installation::query()->where('github_installation_id', $data->id)->firstOrFail();
    }
}
