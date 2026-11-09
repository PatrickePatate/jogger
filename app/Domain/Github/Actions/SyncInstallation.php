<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Support\Facades\DB;

/**
 * Makes our copy of an installation's repositories match what GitHub says it can access right now
 * (adds new ones, updates renamed/visibility changes, removes the ones that are gone).
 */
final class SyncInstallation
{
    public function __construct(private readonly GithubGateway $gateway, private readonly UpsertRepositories $upsert) {}

    /**
     * @throws GithubApiError when the installation is suspended/deleted or GitHub refuses
     * @throws GithubRateLimited
     */
    public function __invoke(Installation $installation): SyncResult
    {
        if ($installation->status !== InstallationStatus::Active) {
            throw new GithubApiError("The installation for {$installation->account_login} is {$installation->status->value}; GitHub will not let Jogger read its repositories.");
        }

        $remote = $this->gateway->listInstallationRepositories($installation);

        return DB::transaction(function () use ($installation, $remote): SyncResult {
            $existing = Repository::query()->where('installation_id', $installation->id)->get()->keyBy('github_repo_id');
            $remoteIds = array_map(fn ($r) => $r->id, $remote);

            $added = 0;
            $updated = 0;
            foreach ($remote as $repo) {
                $known = $existing->get($repo->id);
                if ($known === null) {
                    $added++;
                } elseif ($known->full_name !== $repo->fullName || $known->private !== $repo->private) {
                    $updated++;
                }
            }

            ($this->upsert)($installation, $remote);

            $removed = Repository::query()
                ->where('installation_id', $installation->id)
                ->whereNotIn('github_repo_id', $remoteIds)
                ->delete();

            $installation->forceFill(['synced_at' => now()])->save();

            return new SyncResult($added, $updated, $removed, count($remote));
        });
    }
}
