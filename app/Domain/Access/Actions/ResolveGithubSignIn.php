<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Models\Invitation;
use App\Domain\Github\OAuth\GithubIdentity;
use App\Models\User;
use App\Support\Exceptions\InvitationInvalid;
use App\Support\Exceptions\UserAlreadyExists;
use Illuminate\Support\Facades\DB;

/**
 * The account-matching rules for "Sign in with GitHub" (the only place they live):
 *
 *  1. An account already linked to this GitHub id signs in (unless it was deactivated).
 *  2. Otherwise the identity's *verified* emails are looked up. A matching local account is NOT signed in: it
 *     must confirm its password first, and only then is the GitHub id linked. Unverified emails never match.
 *  3. Otherwise a pending invitation for one of the verified emails creates the account (still no open sign-up).
 *  4. Anything else is refused.
 */
final class ResolveGithubSignIn
{
    public function __construct(private readonly AcceptInvitation $acceptInvitation) {}

    public function __invoke(GithubIdentity $identity): GithubSignInOutcome
    {
        $linked = User::query()->where('github_id', $identity->id)->first();

        if ($linked !== null) {
            return GithubSignInOutcome::signIn($linked);
        }

        if (User::onlyTrashed()->where('github_id', $identity->id)->exists()) {
            return GithubSignInOutcome::refused('This account has been deactivated. Ask an administrator if you need access again.');
        }

        if (! $identity->emailsReadable) {
            return GithubSignInOutcome::refused('GitHub did not share your email addresses, so Jogger cannot match you to an account. An administrator can enable the “Email addresses” account permission (read-only) on the GitHub App; or sign in with your Jogger password and link GitHub from Settings → Security.');
        }

        if ($identity->verifiedEmails === []) {
            return GithubSignInOutcome::refused('Your GitHub account has no verified email address. Verify one on GitHub, or sign in with your Jogger password and link GitHub from Settings → Security.');
        }

        $match = User::query()->whereIn(DB::raw('lower(email)'), $identity->verifiedEmails)->first();

        if ($match !== null) {
            return match (true) {
                $match->github_id !== null => GithubSignInOutcome::refused('The Jogger account for your email is already linked to a different GitHub account. Sign in with your Jogger password and change the link from Settings → Security.'),
                $match->password === null => GithubSignInOutcome::refused('The Jogger account for your email has no password to confirm the link with. Use “Forgot password” to set one, then link GitHub from Settings → Security.'),
                default => GithubSignInOutcome::confirmWithPassword($match),
            };
        }

        $invitation = Invitation::pending()
            ->whereIn('email', $identity->verifiedEmails)
            ->where('expires_at', '>', now())
            ->first();

        if ($invitation === null) {
            return GithubSignInOutcome::refused('This GitHub account is not linked to a Jogger user, and there is no invitation for its verified email addresses. Ask an administrator to invite you.');
        }

        try {
            $user = ($this->acceptInvitation)($invitation, $identity->displayName(), null, ['id' => $identity->id, 'login' => $identity->login]);
        } catch (InvitationInvalid|UserAlreadyExists) {
            return GithubSignInOutcome::refused('That invitation is no longer valid. Ask an administrator to invite you again.');
        }

        return GithubSignInOutcome::joined($user);
    }
}
