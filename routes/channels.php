<?php

use Illuminate\Support\Facades\Broadcast;

/*
| Realtime channels. All are deny-all placeholders until the policies they
| delegate to exist; each later task replaces its closure with a policy check
| (team grants via the visibleTo scopes).
*/

Broadcast::channel('jobs', fn ($user) => false);
Broadcast::channel('repository.{repositoryId}', fn ($user, $repositoryId) => false);
Broadcast::channel('job.{jobId}', fn ($user, $jobId) => false);
Broadcast::channel('host.{hostId}', fn ($user, $hostId) => false);
Broadcast::channel('pool.{poolId}', fn ($user, $poolId) => false);
