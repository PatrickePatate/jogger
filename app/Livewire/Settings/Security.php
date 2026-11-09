<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Domain\Access\Actions\UnlinkGithubAccount;
use App\Domain\Access\Audit;
use App\Domain\Github\Models\GithubApp;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Security')]
final class Security extends Component
{
    /** Current password, required for every sensitive 2FA change. */
    public string $password = '';

    /** Current password for linking/unlinking GitHub (kept separate from the two-factor form). */
    public string $githubPassword = '';

    /** TOTP code entered while confirming setup. */
    public string $code = '';

    /** True right after confirmation or regeneration: show the one-time recovery codes. */
    public bool $showRecoveryCodes = false;

    public function enableTwoFactor(EnableTwoFactorAuthentication $enable): void
    {
        $this->confirmPassword();

        $enable($this->user());
        Audit::record('auth.two_factor_setup_started', $this->user());

        $this->reset('password', 'code');
        $this->showRecoveryCodes = false;
    }

    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirm): void
    {
        $this->validate(['code' => ['required', 'string']]);

        try {
            $confirm($this->user(), $this->code);
        } catch (ValidationException $e) {
            $this->addError('code', $e->errors()['code'][0] ?? 'The provided code was invalid.');

            return;
        }

        Audit::record('auth.two_factor_enabled', $this->user());

        $this->reset('code');
        $this->showRecoveryCodes = true;
        $this->dispatch('toast', type: 'success', message: 'Two-factor authentication is on.');
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $this->confirmPassword();

        $generate($this->user());
        Audit::record('auth.recovery_codes_regenerated', $this->user());

        $this->reset('password');
        $this->showRecoveryCodes = true;
        $this->dispatch('toast', type: 'success', message: 'New recovery codes generated. The old ones no longer work.');
    }

    public function disableTwoFactor(DisableTwoFactorAuthentication $disable): void
    {
        $this->confirmPassword();

        $disable($this->user());
        Audit::record('auth.two_factor_disabled', $this->user());

        $this->reset('password', 'code');
        $this->showRecoveryCodes = false;
        $this->dispatch('toast', type: 'success', message: 'Two-factor authentication is off.');
    }

    /** Asks for the password, then hands over to the OAuth flow that links a GitHub account to this user. */
    public function linkGithub(): mixed
    {
        abort_unless(GithubApp::query()->exists(), 404);

        $this->confirmPassword('githubPassword');

        session()->put('github.link', ['user_id' => $this->user()->getKey(), 'expires_at' => now()->addMinutes(10)->timestamp]);
        $this->reset('githubPassword');

        return $this->redirectRoute('auth.github.link');
    }

    public function unlinkGithub(UnlinkGithubAccount $unlink): void
    {
        $user = $this->user();

        if ($user->password === null) {
            $this->addError('githubPassword', 'Set a password before unlinking GitHub: use “Forgot password” on the sign-in page.');

            return;
        }

        $this->confirmPassword('githubPassword');

        $unlink($user);

        $this->reset('githubPassword');
        $this->dispatch('toast', type: 'success', message: 'GitHub account unlinked.');
    }

    public function render(): View
    {
        $user = $this->user();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return view('livewire.settings.security', [
            'user' => $user,
            'githubAvailable' => GithubApp::query()->exists(),
            'githubLinked' => $user->github_id !== null,
            'githubLogin' => $user->github_login,
            'hasPassword' => $user->password !== null,
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'pending' => $pending,
            'qrSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'secretKey' => $pending ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $this->showRecoveryCodes && $user->two_factor_recovery_codes ? $user->recoveryCodes() : [],
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->refresh();
    }

    /** Accounts without a local password (GitHub-only) skip the check. */
    private function confirmPassword(string $field = 'password'): void
    {
        $user = $this->user();

        if ($user->password !== null && ! Hash::check($this->{$field}, $user->password)) {
            throw ValidationException::withMessages([$field => 'The password is incorrect.']);
        }
    }
}
