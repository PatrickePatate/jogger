<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Github\OAuth\GithubIdentity;
use App\Models\User;
use App\Support\Exceptions\GithubLoginFailed;
use Illuminate\Support\Facades\DB;

final class LinkGithubAccount
{
    /**
     * @throws GithubLoginFailed when the GitHub account belongs to a different Jogger user
     */
    public function __invoke(User $user, GithubIdentity $identity, string $method): void
    {
        DB::transaction(function () use ($user, $identity, $method): void {
            $owner = User::withTrashed()->where('github_id', $identity->id)->first();

            if ($owner !== null && ! $owner->is($user)) {
                throw new GithubLoginFailed('That GitHub account is already linked to another Jogger user.');
            }

            $user->forceFill(['github_id' => $identity->id, 'github_login' => $identity->login])->save();

            Audit::record('auth.github_linked', $user, ['github_login' => $identity->login, 'method' => $method], $user);
        });
    }
}
