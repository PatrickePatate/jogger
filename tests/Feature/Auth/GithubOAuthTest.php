<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Access\Actions\AcceptInvitation;
use App\Domain\Access\Models\Invitation;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\OAuth\GithubIdentity;
use App\Domain\Github\OAuth\GithubOAuth;
use App\Support\Exceptions\GithubLoginFailed;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthUser;
use Mockery;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class GithubOAuthTest extends TestCase
{
    use RefreshDatabase;

    private GithubApp $githubApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubApp = GithubApp::factory()->create(['client_id' => 'Iv1.testclient', 'client_secret' => 'testsecret']);
        config(['app.url' => 'https://jogger.example.com']);
    }

    private function oauthUser(string $token = 'ghu_usertoken'): OAuthUser
    {
        $user = new OAuthUser;
        $user->token = $token;
        $user->map(['id' => 583231, 'nickname' => 'octocat', 'name' => 'The Octocat', 'email' => 'public@example.com', 'avatar' => 'https://avatars.githubusercontent.com/u/583231']);

        return $user;
    }

    /**
     * Socialite is replaced by a provider mock; the expectations double as assertions about how the provider is built.
     */
    private function expectProvider(callable $configure): void
    {
        $provider = Mockery::mock(GithubProvider::class);
        $provider->shouldReceive('setScopes')->once()->with([])->andReturnSelf();   // GitHub Apps have no scopes
        $provider->shouldReceive('enablePKCE')->once()->andReturnSelf();            // PKCE is "strongly recommended"
        $configure($provider);

        Socialite::shouldReceive('buildProvider')->once()
            ->with(GithubProvider::class, [
                'client_id' => 'Iv1.testclient',
                'client_secret' => 'testsecret',
                'redirect' => 'https://jogger.example.com/auth/github/callback',   // from APP_URL, never the request host
            ])->andReturn($provider);
    }

    public function test_availability_follows_whether_an_app_is_connected(): void
    {
        $this->assertTrue((new GithubOAuth)->available());

        GithubApp::query()->delete();
        $this->assertFalse((new GithubOAuth)->available());
    }

    public function test_the_callback_url_comes_from_app_url(): void
    {
        $this->assertSame('https://jogger.example.com/auth/github/callback', GithubOAuth::callbackUrl());

        config(['app.url' => 'https://jogger.example.com/']);
        $this->assertSame('https://jogger.example.com/auth/github/callback', GithubOAuth::callbackUrl());
    }

    public function test_redirect_uses_the_stored_credentials_pkce_and_no_scopes(): void
    {
        $this->expectProvider(fn ($p) => $p->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://github.com/login/oauth/authorize?x=1')));

        $response = (new GithubOAuth)->redirect();

        $this->assertSame('https://github.com/login/oauth/authorize?x=1', $response->getTargetUrl());
    }

    public function test_the_identity_keeps_only_verified_emails_lower_cased_and_unique(): void
    {
        $this->expectProvider(fn ($p) => $p->shouldReceive('user')->once()->andReturn($this->oauthUser()));
        Http::fake(['api.github.com/user/emails' => Http::response([
            ['email' => 'Octo@Example.com', 'verified' => true, 'primary' => true, 'visibility' => 'public'],
            ['email' => 'unverified@example.com', 'verified' => false, 'primary' => false, 'visibility' => null],
            ['email' => 'other@example.com', 'verified' => true, 'primary' => false, 'visibility' => null],
            ['email' => 'octo@example.com', 'verified' => true, 'primary' => false, 'visibility' => null],
            ['email' => 'strange@example.com', 'verified' => 'true'],       // not a real boolean: not trusted
            'garbage',
        ])]);

        $identity = (new GithubOAuth)->identity();

        $this->assertInstanceOf(GithubIdentity::class, $identity);
        $this->assertSame(583231, $identity->id);
        $this->assertSame('octocat', $identity->login);
        $this->assertSame('The Octocat', $identity->name);
        $this->assertSame(['octo@example.com', 'other@example.com'], $identity->verifiedEmails);
        $this->assertTrue($identity->emailsReadable);
        $this->assertNotContains('public@example.com', $identity->verifiedEmails, 'the profile email is never trusted');
    }

    public function test_emails_are_requested_with_the_users_token_and_githubs_headers(): void
    {
        $this->expectProvider(fn ($p) => $p->shouldReceive('user')->once()->andReturn($this->oauthUser('ghu_secret')));
        Http::fake(['api.github.com/user/emails' => Http::response([])]);

        (new GithubOAuth)->identity();

        Http::assertSent(function (Request $r) {
            return $r->method() === 'GET'
                && $r->url() === 'https://api.github.com/user/emails'
                && $r->header('Authorization')[0] === 'Bearer ghu_secret'
                && $r->header('Accept')[0] === 'application/vnd.github+json'
                && $r->header('X-GitHub-Api-Version')[0] === '2022-11-28';
        });
    }

    public function test_a_refused_email_request_means_not_readable_and_fails_closed(): void
    {
        foreach ([403, 404, 401, 500] as $status) {
            $this->expectProvider(fn ($p) => $p->shouldReceive('user')->once()->andReturn($this->oauthUser()));
            Http::fake(['api.github.com/user/emails' => Http::response(['message' => 'Resource not accessible by integration'], $status)]);

            $identity = (new GithubOAuth)->identity();

            $this->assertFalse($identity->emailsReadable, "status {$status}");
            $this->assertSame([], $identity->verifiedEmails);
            $this->assertSame(583231, $identity->id); // the identity itself is still known
        }
    }

    public function test_an_unreachable_email_endpoint_also_fails_closed(): void
    {
        $this->expectProvider(fn ($p) => $p->shouldReceive('user')->once()->andReturn($this->oauthUser()));
        Http::fake(['api.github.com/user/emails' => fn () => throw new ConnectionException('timeout')]);

        $identity = (new GithubOAuth)->identity();

        $this->assertFalse($identity->emailsReadable);
        $this->assertSame([], $identity->verifiedEmails);
    }

    public function test_any_oauth_failure_becomes_one_friendly_exception(): void
    {
        foreach ([new InvalidStateException, new \RuntimeException('bad_verification_code'), new ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'x'))] as $failure) {
            $this->expectProvider(fn ($p) => $p->shouldReceive('user')->once()->andThrow($failure));

            try {
                (new GithubOAuth)->identity();
                $this->fail('expected GithubLoginFailed');
            } catch (GithubLoginFailed $e) {
                $this->assertStringContainsString('try again', $e->getMessage());
                $this->assertStringNotContainsString('bad_verification_code', $e->getMessage()); // no internals leak
            }
        }
        Http::assertNothingSent();
    }

    public function test_without_a_connected_app_nothing_is_attempted(): void
    {
        GithubApp::query()->delete();
        Socialite::shouldReceive('buildProvider')->never();

        $this->expectException(GithubLoginFailed::class);
        (new GithubOAuth)->redirect();
    }

    public function test_the_identity_never_carries_the_access_token(): void
    {
        $this->assertNotContains('token', array_map(fn ($p) => $p->getName(), (new \ReflectionClass(GithubIdentity::class))->getProperties()));
    }

    public function test_an_account_needs_a_password_or_a_github_identity(): void
    {
        $invitation = Invitation::create(['email' => 'x@example.com', 'is_admin' => false, 'expires_at' => now()->addDay()]);

        $this->expectException(InvalidArgumentException::class);
        app(AcceptInvitation::class)($invitation, 'Nobody', null, null);
    }
}
