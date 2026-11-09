<?php

declare(strict_types=1);

namespace App\Domain\Github\OAuth;

use App\Support\Exceptions\GithubLoginFailed;
use Symfony\Component\HttpFoundation\RedirectResponse;

interface GithubIdentityProvider
{
    /** Whether a GitHub App is connected (sign-in with GitHub needs its client credentials). */
    public function available(): bool;

    public function redirect(): RedirectResponse;

    /**
     * Completes the OAuth round trip for the current request (validates state/PKCE, exchanges the code).
     *
     * @throws GithubLoginFailed
     */
    public function identity(): GithubIdentity;
}
