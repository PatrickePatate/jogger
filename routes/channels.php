<?php

use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/*
| Realtime channels. All are deny-all placeholders until the policies they
| delegate to exist; each later task replaces its closure with a policy check
| (team grants via the visibleTo scopes).
*/

// `jobs` carries every job of the instance, so it is for global admins; everyone else follows repositories.
Broadcast::channel('jobs', fn (User $user) => $user->isAdmin());
Broadcast::channel('repository.{repositoryId}', fn (User $user, string $repositoryId) => Str::isUuid($repositoryId) && Repository::query()
    ->visibleTo($user)->whereKey($repositoryId)->exists());
Broadcast::channel('job.{jobId}', fn (User $user, string $jobId) => Str::isUuid($jobId) && WorkflowJob::query()
    ->visibleTo($user)->whereKey($jobId)->exists());
Broadcast::channel('host.{hostId}', fn ($user, $hostId) => false);
Broadcast::channel('pool.{poolId}', fn ($user, $poolId) => false);
