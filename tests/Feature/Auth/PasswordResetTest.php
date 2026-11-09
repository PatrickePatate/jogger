<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_flow_changes_the_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk()->assertSee('Choose a new password');

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'));

        $this->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_weak_or_mismatched_passwords_are_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'long-enough-password', 'password_confirmation' => 'different-password'])
            ->assertSessionHasErrors('password');
    }

    public function test_unknown_email_does_not_send_anything(): void
    {
        Notification::fake();
        User::factory()->create(); // setup must be complete, or every route redirects to /setup

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token', 'email' => $user->email,
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('email');
    }
}
