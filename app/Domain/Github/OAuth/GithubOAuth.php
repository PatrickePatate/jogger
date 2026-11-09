<?php

declare(strict_types=1);

namespace App\Domain\Github\OAuth;

use App\Domain\Github\Models\GithubApp;
use App\Support\Exceptions\GithubLoginFailed;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as OAuthUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

/**
 * "Sign in with GitHub" through the connected GitHub App (its client id/secret live in the database, so the
 * Socialite provider is built on the fly). Follows GitHub's user-authorization flow for GitHub Apps:
 * state + PKCE, no scopes (GitHub Apps have none; access comes from the app's permissions), and a
 * redirect URI that exactly matches the registered callback URL.
 */
final class GithubOAuth implements GithubIdentityProvider
{
    public function available(): bool
    {
        return GithubApp::query()->exists();
    }

    public function redirect(): RedirectResponse
    {
        return $this->provider()->redirect();
    }

    public function identity(): GithubIdentity
    {
        try {
            $user = $this->provider()->user();

            if (! $user instanceof OAuthUser) {
                throw new GithubLoginFailed;
            }
        } catch (Throwable) {
            // Invalid or missing state, an expired code, GitHub being down...: all "try again" for the person.
            throw new GithubLoginFailed;
        }

        [$verified, $readable] = $this->verifiedEmails((string) $user->token);

        return new GithubIdentity(
            id: (int) $user->getId(),
            login: (string) $user->getNickname(),
            name: $user->getName(),
            avatarUrl: $user->getAvatar(),
            verifiedEmails: $verified,
            emailsReadable: $readable,
        );
    }

    /** The callback URL registered with GitHub (built from APP_URL, never from the request host). */
    public static function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/github/callback';
    }

    protected function provider(): GithubProvider
    {
        $app = GithubApp::query()->first() ?? throw new GithubLoginFailed('GitHub sign-in is not set up yet.');

        /** @var GithubProvider $provider */
        $provider = Socialite::buildProvider(GithubProvider::class, [
            'client_id' => $app->client_id,
            'client_secret' => $app->client_secret,
            'redirect' => self::callbackUrl(),
        ]);

        return $provider->setScopes([])->enablePKCE();
    }

    /**
     * Only addresses GitHub marks `verified` count. We read them ourselves instead of trusting the profile's public
     * email or Socialite's pick. If GitHub refuses (the app lacks the "Email addresses" permission) we report
     * "not readable" so callers fail closed.
     *
     * @return array{0: array<int, string>, 1: bool}
     */
    private function verifiedEmails(string $token): array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('jogger.github.api_url'), '/'))
                ->withToken($token)
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => (string) config('jogger.github.api_version'), 'User-Agent' => 'Jogger/'.config('app.name')])
                ->timeout((int) config('jogger.github.timeout'))
                ->get('/user/emails');
        } catch (Throwable) {
            return [[], false];
        }

        if (! $response->successful()) {
            return [[], false];
        }

        $verified = [];
        foreach ((array) $response->json() as $entry) {
            if (is_array($entry) && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
                $verified[] = mb_strtolower(trim($entry['email']));
            }
        }

        return [array_values(array_unique($verified)), true];
    }
}
