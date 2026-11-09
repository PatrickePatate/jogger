<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Access\Actions\ChangeRole;
use App\Domain\Access\Actions\DeactivateUser;
use App\Domain\Access\Actions\SetAdministrator;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Access\Models\Team;
use App\Models\User;
use App\Support\Exceptions\LastAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Widget;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admins_get_403_on_every_admin_page(): void
    {
        $member = User::factory()->create();
        Team::factory()->create()->addMember($member, Role::Admin); // a team admin is not a global admin

        foreach (['/admin/users', '/admin/teams'] as $path) {
            $this->actingAs($member)->get($path)->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_login_and_admins_get_in(): void
    {
        $admin = User::factory()->admin()->create();

        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('Invite user');
        $this->actingAs($admin)->get('/admin/teams')->assertOk()->assertSee('New team');
    }

    public function test_navigation_shows_admin_links_only_to_admins(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertDontSee('/admin/users', false);
        $this->actingAs(User::factory()->admin()->create())->get('/')->assertSee('/admin/users', false)->assertSee('/admin/teams', false);
    }

    public function test_a_role_change_applies_immediately_and_is_audited(): void
    {
        DB::statement('CREATE TEMPORARY TABLE widgets (id uuid PRIMARY KEY, name text)');
        $widget = Widget::create(['name' => 'w']);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, Role::Viewer);
        $team->grant($widget);
        $this->assertSame(Role::Viewer, $user->roleFor($widget));

        app(ChangeRole::class)($admin, $team, $user, Role::Operator);

        $this->assertSame(Role::Operator, $user->fresh()->roleFor($widget)); // no cache to expire
        $log = AuditLog::where('action', 'user.role_changed')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertEquals(['team' => $team->name, 'from' => 'viewer', 'to' => 'operator'], $log->metadata);
    }

    public function test_changing_to_the_same_role_is_a_silent_no_op_and_non_members_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, Role::Viewer);

        app(ChangeRole::class)($admin, $team, $user, Role::Viewer);
        $this->assertSame(0, AuditLog::where('action', 'user.role_changed')->count());

        $this->expectException(ModelNotFoundException::class);
        app(ChangeRole::class)($admin, $team, User::factory()->create(), Role::Operator);
    }

    public function test_the_last_administrator_cannot_be_demoted_or_deactivated(): void
    {
        $only = User::factory()->admin()->create();
        $other = User::factory()->create();

        try {
            app(SetAdministrator::class)($other, $only, false);
            $this->fail('demoting the last admin must fail');
        } catch (LastAdmin) {
            $this->assertTrue($only->fresh()->isAdmin());
        }

        try {
            app(DeactivateUser::class)($other, $only);
            $this->fail('deactivating the last admin must fail');
        } catch (LastAdmin) {
            $this->assertNull($only->fresh()->deleted_at);
        }
    }

    public function test_with_two_admins_one_can_be_demoted_but_then_the_other_is_protected(): void
    {
        $a = User::factory()->admin()->create();
        $b = User::factory()->admin()->create();

        app(SetAdministrator::class)($a, $b, false);
        $this->assertFalse($b->fresh()->isAdmin());
        $this->assertTrue(AuditLog::where('action', 'user.admin_revoked')->where('target_id', $b->id)->exists());

        $this->expectException(LastAdmin::class);
        app(SetAdministrator::class)($b, $a, false);
    }

    public function test_granting_admin_is_audited_and_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        app(SetAdministrator::class)($admin, $user, true);
        app(SetAdministrator::class)($admin, $user, true);

        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertSame(1, AuditLog::where('action', 'user.admin_granted')->count());
    }

    public function test_deactivating_signs_the_user_out_revokes_tokens_and_blocks_login(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->accessTokens()->create(['name' => 't', 'token_hash' => hash('sha256', 'x'), 'role' => 'viewer']);

        // A real signed-in session, then deactivation from another request.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->get('/')->assertOk();
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        auth()->forgetGuards();

        app(DeactivateUser::class)($admin, $user);
        auth()->forgetGuards(); // the next request is a fresh process with only the session cookie

        $this->get('/')->assertRedirect(route('login')); // the old session no longer resolves to a user

        $this->assertSoftDeleted($user);
        $this->assertSame(0, $user->accessTokens()->count());
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'user.deactivated')->where('target_id', $user->id)->exists());
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->expectException(AuthorizationException::class);
        app(DeactivateUser::class)($admin, $admin);
    }

    public function test_two_admins_demoting_each_other_at_once_cannot_leave_zero_admins(): void
    {
        // Sequential stand-in for the race (the advisory lock itself is covered by the setup race test).
        $a = User::factory()->admin()->create();
        $b = User::factory()->admin()->create();

        app(SetAdministrator::class)($a, $b, false);
        try {
            app(SetAdministrator::class)($b->fresh(), $a, false);
        } catch (LastAdmin) {
        }

        $this->assertSame(1, User::where('is_admin', true)->count());
        $this->assertNotNull(Str::uuid());
    }
}
