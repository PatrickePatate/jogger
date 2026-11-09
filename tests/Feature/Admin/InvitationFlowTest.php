<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Access\Actions\InviteUser;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Access\Models\Invitation;
use App\Domain\Access\Models\Team;
use App\Mail\InvitationMail;
use App\Models\User;
use App\Support\Exceptions\UserAlreadyExists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function invite(string $email = 'new@example.com', bool $admin = false, ?Team $team = null, ?Role $role = null): Invitation
    {
        return app(InviteUser::class)(User::factory()->admin()->create(), $email, $admin, $team, $role);
    }

    public function test_inviting_sends_a_signed_link_and_is_audited(): void
    {
        Mail::fake();
        $team = Team::factory()->create(['name' => 'Platform']);

        $invitation = $this->invite('  New@Example.COM ', false, $team, Role::Operator);

        $this->assertSame('new@example.com', $invitation->email);
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($invitation) {
            $this->assertTrue($mail->hasTo('new@example.com'));
            $this->assertStringContainsString('signature=', $mail->invitation->acceptUrl());
            $this->assertStringContainsString("invitations/{$invitation->id}", $mail->invitation->acceptUrl());

            return true;
        });
        $this->assertTrue(AuditLog::where('action', 'user.invited')->where('target_id', $invitation->id)->exists());
    }

    public function test_mail_content_describes_the_invitation(): void
    {
        $team = Team::factory()->create(['name' => 'Platform']);
        $invitation = $this->invite('new@example.com', false, $team, Role::Operator);

        $html = (new InvitationMail($invitation))->render();

        $this->assertStringContainsString('Platform', $html);
        $this->assertStringContainsString('Operator', $html);
        $this->assertStringContainsString('Accept invitation', $html);
    }

    public function test_the_invitee_sets_a_password_and_lands_in_the_assigned_team(): void
    {
        $team = Team::factory()->create();
        $invitation = $this->invite('new@example.com', false, $team, Role::Operator);
        $url = $invitation->acceptUrl();

        $this->get($url)->assertOk()->assertSee('new@example.com')->assertSee($team->name);

        $this->post($url, ['name' => 'Nora New', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'])
            ->assertRedirect(route('overview'));

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->isAdmin());
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('operator', $user->teams()->firstOrFail()->pivot->role);
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertTrue(AuditLog::where('action', 'user.joined')->where('user_id', $user->id)->exists());
    }

    public function test_an_administrator_invitation_creates_an_admin(): void
    {
        $url = $this->invite('boss@example.com', true)->acceptUrl();

        $this->post($url, ['name' => 'Bo Boss', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password']);

        $this->assertTrue(User::where('email', 'boss@example.com')->firstOrFail()->isAdmin());
    }

    public function test_the_link_works_once(): void
    {
        $url = $this->invite()->acceptUrl();
        $payload = ['name' => 'Nora', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

        $this->post($url, $payload);
        $this->post('/logout');

        $this->get($url)->assertStatus(410);
        $this->post($url, $payload)->assertRedirect(route('login'));
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
        $this->assertGuest();
    }

    public function test_tampered_and_expired_links_show_a_friendly_page(): void
    {
        $invitation = $this->invite();

        $this->get(route('invitations.accept', $invitation))->assertForbidden()->assertSee('Ask an administrator to send you a new invitation');
        $this->get($invitation->acceptUrl().'x')->assertForbidden()->assertSee('Ask an administrator to send you a new invitation');

        $url = $invitation->acceptUrl();
        $this->travel(8)->days();
        $this->get($url)->assertForbidden()->assertSee('Ask an administrator to send you a new invitation');
    }

    public function test_weak_passwords_are_rejected_and_nothing_is_created(): void
    {
        $url = $this->invite()->acceptUrl();

        $this->post($url, ['name' => 'Nora', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');

        $this->assertSame(0, User::where('email', 'new@example.com')->count());
        $this->assertGuest();
    }

    public function test_cannot_invite_an_existing_or_deactivated_user(): void
    {
        $existing = User::factory()->create(['email' => 'taken@example.com']);
        $gone = User::factory()->create(['email' => 'gone@example.com']);
        $gone->delete();

        foreach (['taken@example.com', 'gone@example.com'] as $email) {
            try {
                $this->invite($email);
                $this->fail("{$email} should have been refused");
            } catch (UserAlreadyExists) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Invitation::count());
        $this->assertNotNull($existing);
    }

    public function test_reinviting_updates_the_open_invitation_instead_of_duplicating(): void
    {
        Mail::fake();
        $first = $this->invite('same@example.com');
        $second = $this->invite('same@example.com', true);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->refresh()->is_admin);
        $this->assertSame(1, Invitation::count());
        Mail::assertSentCount(2);
    }

    public function test_an_invitation_to_a_since_deleted_team_still_creates_the_account_without_membership(): void
    {
        $team = Team::factory()->create();
        $url = $this->invite('new@example.com', false, $team, Role::Viewer)->acceptUrl();
        $team->delete(); // teams are soft-deleted in the app

        $this->post($url, ['name' => 'Nora', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'])
            ->assertRedirect(route('overview'));

        $this->assertSame(0, User::where('email', 'new@example.com')->firstOrFail()->teams()->count());
    }
}
