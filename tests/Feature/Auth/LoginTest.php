<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        User::factory()->create();

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/settings/security')->assertRedirect(route('login'));
    }

    public function test_login_page_renders_without_registration(): void
    {
        User::factory()->create();

        $this->get('/login')->assertOk()->assertSee('Sign in')->assertSee('Forgot password?');
        $this->get('/register')->assertNotFound();
    }

    public function test_valid_credentials_reach_the_dashboard_and_regenerate_the_session(): void
    {
        $user = User::factory()->create();
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->get('/')->assertOk();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_sixth_failed_attempt_in_a_minute_is_throttled(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
        // Even the right password is refused while throttled.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_soft_deleted_users_cannot_sign_in(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
