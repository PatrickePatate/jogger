<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Access\Actions\LinkGithubAccount;
use App\Domain\Access\Actions\ResolveGithubSignIn;
use App\Domain\Github\OAuth\GithubIdentity;
use App\Domain\Github\OAuth\GithubIdentityProvider;
use App\Models\User;
use App\Support\Exceptions\GithubLoginFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Sign in with GitHub and link/unlink from Settings. All the account-matching rules are in ResolveGithubSignIn;
 * this controller only moves the browser around and handles the session.
 */
final class GithubLoginController
{
    private const LINK_SESSION = 'github.link';

    private const PENDING_SESSION = 'github.pending_link';

    private const WINDOW_MINUTES = 10;

    public function __construct(private readonly GithubIdentityProvider $github) {}

    public function redirect(): SymfonyRedirect
    {
        abort_unless($this->github->available(), 404);

        return $this->github->redirect();
    }

    /** Settings → Security starts linking (after asking for the password) by setting the session flag, then comes here. */
    public function linkRedirect(Request $request): SymfonyRedirect
    {
        abort_unless($this->github->available(), 404);

        $flag = $request->session()->get(self::LINK_SESSION);

        if (! is_array($flag) || ($flag['user_id'] ?? null) !== $request->user()->getKey() || ($flag['expires_at'] ?? 0) < now()->timestamp) {
            return redirect()->route('settings.security')->with('error', 'Confirm your password first, then link GitHub.');
        }

        return $this->github->redirect();
    }

    public function callback(Request $request, ResolveGithubSignIn $resolve, LinkGithubAccount $link): RedirectResponse
    {
        abort_unless($this->github->available(), 404);

        $linking = $this->consumeLinkFlag($request);

        if ($request->query('error') !== null) { // the person pressed "Cancel" on GitHub
            return $linking
                ? redirect()->route('settings.security')->with('error', 'GitHub linking was cancelled.')
                : redirect()->route('login')->with('error', 'GitHub sign-in was cancelled.');
        }

        try {
            $identity = $this->github->identity();
        } catch (GithubLoginFailed $e) {
            return $linking
                ? redirect()->route('settings.security')->with('error', $e->getMessage())
                : redirect()->route('login')->with('error', $e->getMessage());
        }

        if ($linking !== null) {
            return $this->finishLinking($request, $linking, $identity, $link);
        }

        if (Auth::check()) {
            return redirect()->route('overview'); // already signed in; nothing to do
        }

        $outcome = $resolve($identity);

        if ($outcome->isRefusal()) {
            return redirect()->route('login')->with('error', $outcome->message);
        }

        /** @var User $user */
        $user = $outcome->user;

        if ($outcome->kind === 'confirm_password') {
            $request->session()->put(self::PENDING_SESSION, [
                'user_id' => $user->getKey(),
                'identity' => ['id' => $identity->id, 'login' => $identity->login, 'name' => $identity->name, 'avatar' => $identity->avatarUrl, 'emails' => $identity->verifiedEmails],
                'expires_at' => now()->addMinutes(self::WINDOW_MINUTES)->timestamp,
            ]);

            return redirect()->route('auth.github.confirm');
        }

        $this->refreshLogin($user, $identity->login);

        return $this->completeSignIn($request, $user);
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('login')->with('error', 'That link request expired. Please start again.');
        }

        return view('auth.github-confirm', ['githubLogin' => $pending['identity']['login'], 'email' => User::query()->find($pending['user_id'])?->email]);
    }

    public function confirmStore(Request $request, LinkGithubAccount $link): RedirectResponse
    {
        $pending = $this->pending($request);
        $user = $pending ? User::query()->find($pending['user_id']) : null;

        if ($pending === null || $user === null) {
            return redirect()->route('login')->with('error', 'That link request expired. Please start again.');
        }

        $request->validate(['password' => ['required', 'string']]);

        $key = 'github-link:'.$user->getKey().'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        if ($user->password === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING_SESSION);

        $identity = new GithubIdentity(
            (int) $pending['identity']['id'], (string) $pending['identity']['login'], $pending['identity']['name'] ?? null,
            $pending['identity']['avatar'] ?? null, (array) ($pending['identity']['emails'] ?? []), true,
        );

        try {
            $link($user, $identity, 'email-match');
        } catch (GithubLoginFailed $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        return $this->completeSignIn($request, $user);
    }

    /**
     * @param  array{user_id: string}  $flag
     */
    private function finishLinking(Request $request, array $flag, GithubIdentity $identity, LinkGithubAccount $link): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || $flag['user_id'] !== $user->getKey()) {
            return redirect()->route('login')->with('error', 'Please sign in again before linking GitHub.');
        }

        try {
            $link($user, $identity, 'settings');
        } catch (GithubLoginFailed $e) {
            return redirect()->route('settings.security')->with('error', $e->getMessage());
        }

        return redirect()->route('settings.security')->with('status', "GitHub account @{$identity->login} linked.");
    }

    /** A Jogger user with two-factor enabled still has to pass the challenge: GitHub only replaces the password. */
    private function completeSignIn(Request $request, User $user): RedirectResponse
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    private function refreshLogin(User $user, string $login): void
    {
        if ($user->github_login !== $login) {
            $user->forceFill(['github_login' => $login])->save(); // people rename themselves; the id is the identity
        }
    }

    /**
     * @return array{user_id: string}|null
     */
    private function consumeLinkFlag(Request $request): ?array
    {
        $flag = $request->session()->pull(self::LINK_SESSION);

        return is_array($flag) && ($flag['expires_at'] ?? 0) >= now()->timestamp ? $flag : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING_SESSION);

        return is_array($pending) && ($pending['expires_at'] ?? 0) >= now()->timestamp ? $pending : null;
    }
}
