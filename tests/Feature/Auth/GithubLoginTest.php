<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Access\Actions\InviteUser;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Access\Models\Invitation;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\GithubApp;
use App\Livewire\Settings\Security;
use App\Models\User;
use App\Support\Exceptions\GithubLoginFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeGithubIdentityProvider as Fake;
use Tests\TestCase;

class GithubLoginTest extends TestCase
{
    use RefreshDatabase;

    private Fake $github;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(); // setup must be complete, or every route redirects to /setup
        GithubApp::factory()->create();
        $this->github = Fake::install();
        Mail::fake();
    }

    private function hitCallback(array $session = [], string $query = ''): TestResponse
    {
        return $this->withSession($session)->get('/auth/github/callback'.$query);
    }

    private function linkedUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['github_id' => 583231, 'github_login' => 'octocat']);
    }

    // ---------------------------------------------------------------- the button and the redirect

    public function test_the_login_page_offers_github_only_when_an_app_is_connected(): void
    {
        $this->get('/login')->assertSee('Continue with GitHub')->assertSeeHtml('data-testid="github-signin"')
            ->assertSeeHtml('href="'.route('auth.github.redirect').'"');

        GithubApp::query()->delete();
        $this->get('/login')->assertDontSee('Continue with GitHub');
    }

    public function test_the_redirect_sends_guests_to_github_and_404s_without_an_app(): void
    {
        User::factory()->create();

        $this->get('/auth/github/redirect')->assertRedirect('https://github.com/login/oauth/authorize?client_id=fake');
        $this->assertSame(1, $this->github->redirects);

        $this->github->available = false;
        $this->get('/auth/github/redirect')->assertNotFound();
        $this->get('/auth/github/callback')->assertNotFound();
    }

    public function test_signed_in_users_are_not_sent_through_the_sign_in_redirect(): void
    {
        $this->actingAs(User::factory()->create())->get('/auth/github/redirect')->assertRedirect(route('overview'));
        $this->assertSame(0, $this->github->redirects);
    }

    // ---------------------------------------------------------------- rule 1: already linked

    public function test_a_linked_github_id_signs_in_directly_and_regenerates_the_session(): void
    {
        $user = $this->linkedUser();
        $this->get('/login');
        $before = session()->getId();

        $this->hitCallback()->assertRedirect(route('overview'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_github_login_name_follows_renames_but_the_id_is_the_identity(): void
    {
        $user = $this->linkedUser();
        $this->github->identity = Fake::make(login: 'octocat-renamed', verifiedEmails: []);

        $this->hitCallback();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('octocat-renamed', $user->fresh()->github_login);
    }

    public function test_a_linked_user_does_not_need_readable_emails(): void
    {
        $this->linkedUser();
        $this->github->identity = Fake::make(verifiedEmails: [], emailsReadable: false);

        $this->hitCallback()->assertRedirect(route('overview'));
    }

    public function test_a_deactivated_linked_user_is_refused(): void
    {
        $user = $this->linkedUser();
        $user->delete();

        $this->hitCallback()->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertStringContainsString('deactivated', (string) session('error'));
        $this->assertGuest();
    }

    public function test_two_factor_still_applies_to_a_github_sign_in(): void
    {
        $user = $this->linkedUser();
        app(EnableTwoFactorAuthentication::class)($user);
        $secret = Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret);
        $g = new Google2FA;
        app(ConfirmTwoFactorAuthentication::class)($user, $g->oathTotp($secret, $g->getTimestamp()));

        $this->hitCallback()->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        // Completing the challenge in the same session finishes the sign-in.
        $this->withSession(['login.id' => $user->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['code' => $g->oathTotp($secret, $g->getTimestamp() - 1)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    // ---------------------------------------------------------------- rule 2: matching email needs the password

    public function test_a_verified_matching_email_asks_for_the_password_and_does_not_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'octo@example.com']);

        $this->hitCallback()->assertRedirect(route('auth.github.confirm'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->github_id, 'nothing is linked before the password is confirmed');
    }

    public function test_the_email_match_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'Octo@Example.com']);

        $this->hitCallback()->assertRedirect(route('auth.github.confirm'));
    }

    public function test_confirming_the_password_links_the_account_and_signs_in(): void
    {
        $user = User::factory()->create(['email' => 'octo@example.com']);
        $pending = $this->pending($user);

        $this->withSession($pending)->get('/auth/github/confirm')->assertOk()->assertSee('@octocat')->assertSee('octo@example.com');
        $this->withSession($pending)->post('/auth/github/confirm', ['password' => 'password'])->assertRedirect(route('overview'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(583231, $user->fresh()->github_id);
        $this->assertSame('octocat', $user->fresh()->github_login);
        $log = AuditLog::where('action', 'auth.github_linked')->firstOrFail();
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame('email-match', $log->metadata['method']);
    }

    public function test_a_wrong_password_links_nothing_and_attempts_are_limited(): void
    {
        $user = User::factory()->create(['email' => 'octo@example.com']);
        $pending = $this->pending($user);

        foreach (range(1, 5) as $i) {
            $this->withSession($pending)->post('/auth/github/confirm', ['password' => 'nope'])->assertSessionHasErrors('password');
        }
        // Locked out: even the right password is refused now.
        $this->withSession($pending)->post('/auth/github/confirm', ['password' => 'password'])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNull($user->fresh()->github_id);
        $this->assertStringContainsString('Too many attempts', collect(session('errors')->get('password'))->first());
    }

    public function test_the_confirmation_page_needs_a_pending_request_and_expires(): void
    {
        $user = User::factory()->create(['email' => 'octo@example.com']);

        $this->get('/auth/github/confirm')->assertRedirect(route('login'));
        $this->post('/auth/github/confirm', ['password' => 'password'])->assertRedirect(route('login'));

        $expired = $this->pending($user);
        $expired['github.pending_link']['expires_at'] = now()->subMinute()->timestamp;
        $this->withSession($expired)->get('/auth/github/confirm')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_pending_link_cannot_be_completed_by_someone_else_with_another_users_password(): void
    {
        $victim = User::factory()->create(['email' => 'octo@example.com', 'password' => 'victim-password-123']);
        User::factory()->create(['email' => 'attacker@example.com', 'password' => 'attacker-password-1']);

        $this->withSession($this->pending($victim))->post('/auth/github/confirm', ['password' => 'attacker-password-1'])->assertSessionHasErrors('password');

        $this->assertNull($victim->fresh()->github_id);
        $this->assertGuest();
    }

    public function test_a_two_factor_account_still_faces_the_challenge_after_confirming(): void
    {
        $user = User::factory()->create(['email' => 'octo@example.com']);
        app(EnableTwoFactorAuthentication::class)($user);
        $secret = Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret);
        $g = new Google2FA;
        app(ConfirmTwoFactorAuthentication::class)($user, $g->oathTotp($secret, $g->getTimestamp()));

        $this->withSession($this->pending($user))->post('/auth/github/confirm', ['password' => 'password'])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame(583231, $user->fresh()->github_id);
    }

    public function test_unverified_or_unreadable_emails_never_match_an_account(): void
    {
        $user = User::factory()->create(['email' => 'octo@example.com']);

        // GitHub lists the address but unverified: it is not in verifiedEmails.
        $this->github->identity = Fake::make(verifiedEmails: []);
        $this->hitCallback()->assertRedirect(route('login'));
        $this->assertStringContainsString('no verified email', (string) session('error'));

        // GitHub refused to share emails at all (permission missing).
        $this->github->identity = Fake::make(verifiedEmails: [], emailsReadable: false);
        $this->hitCallback()->assertRedirect(route('login'));
        $this->assertStringContainsString('Email addresses', (string) session('error'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->github_id);
    }

    public function test_an_account_linked_to_a_different_github_user_or_without_a_password_is_refused(): void
    {
        $elsewhere = User::factory()->create(['email' => 'octo@example.com', 'github_id' => 999]);
        $this->hitCallback()->assertRedirect(route('login'));
        $this->assertStringContainsString('already linked to a different GitHub account', (string) session('error'));
        $this->assertSame(999, $elsewhere->fresh()->github_id);

        $elsewhere->forceFill(['github_id' => null, 'password' => null])->save();
        $this->hitCallback()->assertRedirect(route('login'));
        $this->assertStringContainsString('no password', (string) session('error'));
        $this->assertGuest();
    }

    // ---------------------------------------------------------------- rule 3 and 4: invitations only

    public function test_an_unknown_identity_is_refused_and_no_account_is_created(): void
    {
        User::factory()->create();
        $before = User::count();

        $this->hitCallback()->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertStringContainsString('Ask an administrator to invite you', (string) session('error'));
        $this->assertGuest();
        $this->assertSame($before, User::count());
    }

    public function test_an_invited_email_creates_the_account_with_its_team_and_signs_in(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create();
        $invitation = app(InviteUser::class)($admin, 'octo@example.com', false, $team, Role::Operator);

        $this->hitCallback()->assertRedirect(route('overview'));

        $user = User::where('email', 'octo@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('The Octocat', $user->name);
        $this->assertSame(583231, $user->github_id);
        $this->assertSame('octocat', $user->github_login);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->isAdmin());
        $this->assertSame('operator', $user->teams()->firstOrFail()->pivot->role);
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $log = AuditLog::where('action', 'user.joined')->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('github', $log->metadata['method']);
    }

    public function test_an_administrator_invitation_works_through_github_too(): void
    {
        app(InviteUser::class)(User::factory()->admin()->create(), 'octo@example.com', true);

        $this->hitCallback();

        $this->assertTrue(User::where('email', 'octo@example.com')->firstOrFail()->isAdmin());
    }

    public function test_invitations_only_count_for_verified_emails_and_only_while_valid(): void
    {
        $admin = User::factory()->admin()->create();
        $invitation = app(InviteUser::class)($admin, 'invited@example.com');

        // The GitHub account verifies a *different* address than the one invited.
        $this->hitCallback()->assertSessionHas('error');
        $this->assertSame(0, User::where('email', 'invited@example.com')->count());

        // Expired invitation.
        $this->github->identity = Fake::make(verifiedEmails: ['invited@example.com']);
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->hitCallback()->assertSessionHas('error');
        $this->assertSame(0, User::where('email', 'invited@example.com')->count());

        // Already accepted.
        $invitation->forceFill(['expires_at' => now()->addDay(), 'accepted_at' => now()])->save();
        $this->hitCallback()->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_an_invitation_is_consumed_once_and_a_second_github_account_cannot_reuse_it(): void
    {
        app(InviteUser::class)(User::factory()->admin()->create(), 'octo@example.com');
        $this->hitCallback();
        $this->post('/logout');

        // A different GitHub account with the same verified email: the existing account is already linked to someone else, so it is refused.
        $this->github->identity = Fake::make(id: 777, login: 'impostor', verifiedEmails: ['octo@example.com']);
        $this->hitCallback()->assertRedirect(route('login'));

        $this->assertStringContainsString('already linked to a different GitHub account', (string) session('error'));
        $this->assertGuest();
        $this->assertSame(1, User::where('email', 'octo@example.com')->count());
        $this->assertSame(583231, User::where('email', 'octo@example.com')->firstOrFail()->github_id);
    }

    // ---------------------------------------------------------------- failures and cancellation

    public function test_a_failed_oauth_round_trip_shows_a_clear_error(): void
    {
        $this->github->failure = new GithubLoginFailed;

        $this->hitCallback()->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_cancelling_on_github_returns_to_the_login_page_without_calling_github(): void
    {
        $this->hitCallback(query: '?error=access_denied&error_description=The+user+denied')->assertRedirect(route('login'));

        $this->assertStringContainsString('cancelled', (string) session('error'));
        $this->assertSame(0, $this->github->identityCalls);
    }

    // ---------------------------------------------------------------- linking from Settings

    private function pending(User $user): array
    {
        return ['github.pending_link' => [
            'user_id' => $user->getKey(),
            'identity' => ['id' => 583231, 'login' => 'octocat', 'name' => 'The Octocat', 'avatar' => null, 'emails' => ['octo@example.com']],
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]];
    }

    private function linkFlag(User $user, ?int $expires = null): array
    {
        return ['github.link' => ['user_id' => $user->getKey(), 'expires_at' => $expires ?? now()->addMinutes(10)->timestamp]];
    }

    public function test_linking_from_settings_requires_the_password_first(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Security::class)->set('githubPassword', 'wrong')->call('linkGithub')->assertHasErrors('githubPassword');
        $this->assertNull(session('github.link'));

        Livewire::actingAs($user)->test(Security::class)->set('githubPassword', 'password')->call('linkGithub')->assertRedirect(route('auth.github.link'));
        $this->assertSame($user->id, session('github.link.user_id'));
    }

    public function test_the_link_redirect_only_works_with_a_fresh_flag_for_the_signed_in_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/auth/github/link')->assertRedirect(route('settings.security'))->assertSessionHas('error');
        $this->actingAs($user)->withSession($this->linkFlag(User::factory()->create()))->get('/auth/github/link')->assertRedirect(route('settings.security'));
        $this->actingAs($user)->withSession($this->linkFlag($user, now()->subMinute()->timestamp))->get('/auth/github/link')->assertRedirect(route('settings.security'));
        $this->assertSame(0, $this->github->redirects);

        $this->actingAs($user)->withSession($this->linkFlag($user))->get('/auth/github/link')->assertRedirect('https://github.com/login/oauth/authorize?client_id=fake');
    }

    public function test_the_callback_links_the_signed_in_user_without_needing_emails(): void
    {
        $user = User::factory()->create();
        $this->github->identity = Fake::make(verifiedEmails: [], emailsReadable: false);

        $this->actingAs($user)->withSession($this->linkFlag($user))->get('/auth/github/callback')->assertRedirect(route('settings.security'))->assertSessionHas('status');

        $this->assertSame(583231, $user->fresh()->github_id);
        $this->assertSame('settings', AuditLog::where('action', 'auth.github_linked')->firstOrFail()->metadata['method']);
    }

    public function test_a_github_account_cannot_be_linked_to_two_users(): void
    {
        $this->linkedUser();
        $second = User::factory()->create();

        $this->actingAs($second)->withSession($this->linkFlag($second))->get('/auth/github/callback')->assertRedirect(route('settings.security'))->assertSessionHas('error');

        $this->assertNull($second->fresh()->github_id);
    }

    public function test_a_signed_in_user_hitting_the_callback_without_a_link_flag_is_not_linked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/auth/github/callback')->assertRedirect(route('overview'));

        $this->assertNull($user->fresh()->github_id);
    }

    public function test_a_link_flag_is_single_use(): void
    {
        $user = User::factory()->create();
        $other = $this->github->identity;

        $this->actingAs($user)->withSession($this->linkFlag($user))->get('/auth/github/callback');
        $user->forceFill(['github_id' => null, 'github_login' => null])->save();
        $this->github->identity = $other;

        $this->actingAs($user)->get('/auth/github/callback')->assertRedirect(route('overview')); // no flag left: nothing happens

        $this->assertNull($user->fresh()->github_id);
    }

    // ---------------------------------------------------------------- unlinking

    public function test_unlinking_needs_the_password_and_is_audited(): void
    {
        $user = $this->linkedUser();

        Livewire::actingAs($user)->test(Security::class)->set('githubPassword', 'wrong')->call('unlinkGithub')->assertHasErrors('githubPassword');
        $this->assertSame(583231, $user->fresh()->github_id);

        Livewire::actingAs($user)->test(Security::class)->set('githubPassword', 'password')->call('unlinkGithub')->assertHasNoErrors()->assertDispatched('toast', type: 'success');

        $this->assertNull($user->fresh()->github_id);
        $this->assertNull($user->fresh()->github_login);
        $this->assertSame('octocat', AuditLog::where('action', 'auth.github_unlinked')->firstOrFail()->metadata['github_login']);

        // And GitHub no longer signs this person in (the verified email does not match their account either).
        auth()->logout();
        $this->hitCallback()->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_unlinking_is_impossible_without_a_password(): void
    {
        $user = $this->linkedUser(['password' => null]);

        Livewire::actingAs($user)->test(Security::class)
            ->assertSeeHtml('data-testid="github-no-password"')->assertSee('Forgot password')
            ->call('unlinkGithub')->assertHasErrors('githubPassword');

        $this->assertSame(583231, $user->fresh()->github_id);
        $this->assertSame(0, AuditLog::where('action', 'auth.github_unlinked')->count());
    }

    public function test_the_security_page_reflects_the_link_state(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Security::class)->assertSee('Not linked')->assertSeeHtml('data-testid="link-github"')->assertDontSee('Unlink GitHub');

        $user->forceFill(['github_id' => 583231, 'github_login' => 'octocat'])->save();
        Livewire::actingAs($user)->test(Security::class)->assertSeeHtml('data-testid="github-badge"')->assertSee('Linked · @octocat')->assertSee('Unlink GitHub')->assertDontSeeHtml('data-testid="link-github"');

        GithubApp::query()->delete();
        Livewire::actingAs($user)->test(Security::class)->assertDontSee('Unlink GitHub');
    }

    public function test_the_flash_messages_from_the_flow_are_shown_on_the_security_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['status' => 'GitHub account @octocat linked.'])->get('/settings/security')->assertSee('GitHub account @octocat linked.');
        $this->actingAs($user)->withSession(['error' => 'That GitHub account is already linked to another Jogger user.'])->get('/settings/security')->assertSee('already linked to another Jogger user');
    }

    public function test_the_connect_page_explains_the_email_permission(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/github')->assertSeeHtml('data-testid="email-permission-hint"')->assertSee('Email addresses');
    }
}
