<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Access\Actions\GrantTeamAccess;
use App\Domain\Access\Actions\InviteUser;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Access\Models\Invitation;
use App\Domain\Access\Models\Team;
use App\Livewire\Admin\Teams;
use App\Livewire\Admin\Users;
use App\Mail\InvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Widget;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Ada Admin']);
    }

    public function test_users_page_lists_filters_and_shows_deactivated(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']);
        User::factory()->create(['name' => 'Carol Carter', 'email' => 'carol@example.com'])->delete();

        Livewire::actingAs($admin)->test(Users::class)
            ->assertSee('Bob Builder')->assertSee('Ada Admin')->assertDontSee('Carol Carter')
            ->set('search', 'bob')->assertSee('Bob Builder')->assertDontSee('Ada Admin')
            ->set('search', '')->set('deactivated', true)->assertSee('Carol Carter')->assertDontSee('Bob Builder');
    }

    public function test_inviting_from_the_page_sends_mail_and_lists_the_pending_invitation(): void
    {
        Mail::fake();
        $team = Team::factory()->create(['name' => 'Platform']);

        Livewire::actingAs($this->admin())->test(Users::class)
            ->set('inviteEmail', 'new@example.com')->set('inviteTeamId', $team->id)->set('inviteRole', 'operator')
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSee('new@example.com')->assertSee('Platform')->assertSet('inviteEmail', '')
            ->assertDispatched('close-modal', name: 'invite');

        Mail::assertSent(InvitationMail::class);
        $this->assertSame('operator', Invitation::firstOrFail()->role->value);
    }

    public function test_invite_validation_and_duplicate_handling(): void
    {
        Mail::fake();
        $admin = $this->admin();
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($admin)->test(Users::class)
            ->set('inviteEmail', 'nope')->call('invite')->assertHasErrors('inviteEmail')
            ->set('inviteEmail', 'taken@example.com')->call('invite')->assertDispatched('toast', type: 'error', message: 'A user with that email already exists.');

        $this->assertSame(0, Invitation::count());
    }

    public function test_resend_and_revoke_invitation(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $invitation = app(InviteUser::class)($admin, 'x@example.com');

        Livewire::actingAs($admin)->test(Users::class)->call('resend', $invitation->id)->call('revokeInvitation', $invitation->id);

        Mail::assertSentCount(2);
        $this->assertSame(0, Invitation::count());
        $this->assertTrue(AuditLog::where('action', 'user.invitation_revoked')->exists());
    }

    public function test_admin_toggle_and_last_admin_protection_surface_as_toasts(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(Users::class)->call('setAdministrator', $user->id, true);
        $this->assertTrue($user->fresh()->isAdmin());

        $component->call('setAdministrator', $user->id, false);
        $this->assertFalse($user->fresh()->isAdmin());

        $component->call('setAdministrator', $admin->id, false)
            ->assertDispatched('toast', type: 'error', message: 'At least one administrator must remain.');
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_deactivate_and_reactivate_from_the_page(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        Livewire::actingAs($admin)->test(Users::class)->call('deactivate', $user->id)->assertDispatched('toast', type: 'success');
        $this->assertSoftDeleted($user);

        Livewire::actingAs($admin)->test(Users::class)->call('reactivate', $user->id);
        $this->assertNotSoftDeleted($user);

        Livewire::actingAs($admin)->test(Users::class)->call('deactivate', $admin->id)
            ->assertDispatched('toast', type: 'error');
        $this->assertNotSoftDeleted($admin);
    }

    public function test_livewire_actions_are_admin_only_even_when_called_directly(): void
    {
        $member = User::factory()->create();
        $victim = User::factory()->create();

        Livewire::actingAs($member)->test(Users::class)->call('deactivate', $victim->id)->assertForbidden();
        Livewire::actingAs($member)->test(Users::class)->call('setAdministrator', $member->id, true)->assertForbidden();
        Livewire::actingAs($member)->test(Teams::class)->set('newTeamName', 'Sneaky')->call('createTeam')->assertForbidden();

        $this->assertNotSoftDeleted($victim);
        $this->assertFalse($member->fresh()->isAdmin());
        $this->assertSame(0, Team::count());
    }

    public function test_team_management(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Bob Builder']);

        $component = Livewire::actingAs($admin)->test(Teams::class)
            ->set('newTeamName', 'Platform Engineering')->call('createTeam')->assertHasNoErrors()
            ->assertSee('Platform Engineering')->assertSee('No members yet');

        $team = Team::firstOrFail();
        $this->assertSame('platform-engineering', $team->slug);

        $component->set('newMemberId', $user->id)->set('newMemberRole', 'operator')->call('addMember')->assertHasNoErrors()->assertSee('Bob Builder');
        $this->assertSame('operator', $team->members()->firstOrFail()->pivot->role);

        $component->call('changeRole', $user->id, 'viewer');
        $this->assertSame('viewer', $team->members()->firstOrFail()->pivot->role);

        $component->call('removeMember', $user->id);
        $this->assertSame(0, $team->members()->count());

        $component->call('deleteTeam');
        $this->assertSoftDeleted($team);

        $this->assertSame(
            ['team.created', 'team.member_added', 'user.role_changed', 'team.member_removed', 'team.deleted'],
            AuditLog::orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_team_slugs_stay_unique(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(Teams::class)
            ->set('newTeamName', 'Ops')->call('createTeam')->set('newTeamName', 'Ops')->call('createTeam');

        $this->assertEqualsCanonicalizing(['ops', 'ops-2'], Team::pluck('slug')->all());
    }

    public function test_grants_are_listed_on_the_team_page(): void
    {
        DB::statement('CREATE TEMPORARY TABLE widgets (id uuid PRIMARY KEY, name text)');
        $team = Team::factory()->create();
        $widget = Widget::create(['name' => 'w']);
        app(GrantTeamAccess::class)($this->admin(), $team, $widget);

        Livewire::actingAs($this->admin())->test(Teams::class)->call('selectTeam', $team->id)->assertSee('Widget')->assertSee($widget->id);

        app(GrantTeamAccess::class)->revoke($this->admin(), $team, $widget);
        $this->assertSame(0, $team->grants()->count());
        $this->assertSame(['team.access_granted', 'team.access_revoked'], AuditLog::orderBy('id')->pluck('action')->all());
    }
}
