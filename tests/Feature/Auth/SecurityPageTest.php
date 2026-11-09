<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecurityPageTest extends TestCase
{
    use RefreshDatabase;

    private function otp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret));
    }

    public function test_page_renders_for_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/security')
            ->assertOk()->assertSee('Two-factor authentication')->assertSee('Turn on');
    }

    public function test_enabling_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Security::class)
            ->set('password', 'wrong')->call('enableTwoFactor')
            ->assertHasErrors('password');

        $this->assertNull($user->refresh()->two_factor_secret);
    }

    public function test_full_setup_flow_shows_qr_then_recovery_codes_once(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Security::class)
            ->set('password', 'password')->call('enableTwoFactor')
            ->assertSee('Setup not finished')
            ->assertSeeHtml('data-testid="two-factor-qr"')
            ->assertSee('Scan this QR code');

        $this->assertNotNull($user->refresh()->two_factor_secret);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        $component->set('code', '000000')->call('confirmTwoFactor')->assertHasErrors('code');
        $this->assertFalse($user->refresh()->hasEnabledTwoFactorAuthentication());

        $component->set('code', $this->otp($user))->call('confirmTwoFactor')
            ->assertHasNoErrors()
            ->assertSee('Save your recovery codes')
            ->assertSeeHtml('data-testid="recovery-codes"');

        $this->assertTrue($user->refresh()->hasEnabledTwoFactorAuthentication());
        $this->assertCount(8, $user->recoveryCodes());

        // Reloading the page never re-displays the codes.
        Livewire::actingAs($user)->test(Security::class)->assertDontSee('Save your recovery codes');
    }

    public function test_regenerating_codes_requires_the_password_and_replaces_them(): void
    {
        $user = $this->enabledUser();
        $old = $user->recoveryCodes();

        Livewire::actingAs($user)->test(Security::class)
            ->set('password', 'wrong')->call('regenerateRecoveryCodes')->assertHasErrors('password');
        $this->assertSame($old, $user->refresh()->recoveryCodes());

        Livewire::actingAs($user)->test(Security::class)
            ->set('password', 'password')->call('regenerateRecoveryCodes')
            ->assertSee('Save your recovery codes');
        $this->assertNotSame($old, $user->refresh()->recoveryCodes());
    }

    public function test_disabling_requires_the_password_and_clears_everything(): void
    {
        $user = $this->enabledUser();

        Livewire::actingAs($user)->test(Security::class)
            ->set('password', 'wrong')->call('disableTwoFactor')->assertHasErrors('password');
        $this->assertTrue($user->refresh()->hasEnabledTwoFactorAuthentication());

        Livewire::actingAs($user)->test(Security::class)
            ->set('password', 'password')->call('disableTwoFactor')->assertHasNoErrors();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_accounts_without_a_local_password_can_manage_two_factor(): void
    {
        $user = User::factory()->create(['password' => null]);

        Livewire::actingAs($user)->test(Security::class)->call('enableTwoFactor')->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->two_factor_secret);
    }

    private function enabledUser(): User
    {
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test(Security::class)->set('password', 'password')->call('enableTwoFactor');
        $component->set('code', $this->otp($user))->call('confirmTwoFactor');

        return $user->refresh();
    }
}
