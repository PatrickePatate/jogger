<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Github\OAuth\GithubIdentity;
use App\Domain\Github\OAuth\GithubIdentityProvider;
use App\Support\Exceptions\GithubLoginFailed;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Stands in for GitHub in controller tests: hands back a chosen identity (or a failure). */
final class FakeGithubIdentityProvider implements GithubIdentityProvider
{
    public bool $available = true;

    public ?GithubIdentity $identity = null;

    public ?GithubLoginFailed $failure = null;

    public int $redirects = 0;

    public int $identityCalls = 0;

    public static function install(?GithubIdentity $identity = null): self
    {
        $fake = new self;
        $fake->identity = $identity ?? self::make();
        app()->instance(GithubIdentityProvider::class, $fake);

        return $fake;
    }

    /**
     * @param  array<int, string>  $verifiedEmails
     */
    public static function make(int $id = 583231, string $login = 'octocat', ?string $name = 'The Octocat', array $verifiedEmails = ['octo@example.com'], bool $emailsReadable = true): GithubIdentity
    {
        return new GithubIdentity($id, $login, $name, 'https://avatars.githubusercontent.com/u/583231', $verifiedEmails, $emailsReadable);
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function redirect(): RedirectResponse
    {
        $this->redirects++;

        return new RedirectResponse('https://github.com/login/oauth/authorize?client_id=fake');
    }

    public function identity(): GithubIdentity
    {
        $this->identityCalls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->identity ?? throw new GithubLoginFailed;
    }
}
