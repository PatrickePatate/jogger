<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTwoFactor(): User
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);
        app(ConfirmTwoFactorAuthentication::class)($user, $this->otp($user));

        return $user->refresh();
    }

    /**
     * Fortify rejects a code it has already seen (replay protection), so login codes come
     * from the previous 30-second window: still inside the accepted verification window, but a
     * different code from the one used to confirm setup. Fortify records the *current* window
     * when a code is accepted, so a previous-window code can be used once and never replayed.
     */
    private function otp(User $user, int $windowOffset = 0): string
    {
        $google2fa = new Google2FA;
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $windowOffset);
    }

    public function test_login_demands_a_code_before_authenticating(): void
    {
        $user = $this->userWithTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Two-factor authentication');
    }

    public function test_a_valid_totp_code_completes_the_login(): void
    {
        $user = $this->userWithTwoFactor();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/two-factor-challenge', ['code' => $this->otp($user, -1)])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_code_does_not_authenticate(): void
    {
        $user = $this->userWithTwoFactor();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $user = $this->userWithTwoFactor();
        $code = $this->otp($user, -1);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/');
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_codes_work_exactly_once(): void
    {
        $user = $this->userWithTwoFactor();
        $code = $user->recoveryCodes()[0];

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $code])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();

        $this->assertNotContains($code, $user->refresh()->recoveryCodes());
    }

    public function test_unconfirmed_setup_does_not_trigger_the_challenge(): void
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user); // secret generated, never confirmed

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }
}
