<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Widget;
use Tests\TestCase;

class VisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Widget $a;

    private Widget $b;

    private Widget $c;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TEMPORARY TABLE widgets (id uuid PRIMARY KEY, name text)');
        $this->a = Widget::create(['name' => 'a']);
        $this->b = Widget::create(['name' => 'b']);
        $this->c = Widget::create(['name' => 'c']);
    }

    /**
     * @return list<string>
     */
    private function visibleNames(User $user): array
    {
        return Widget::query()->visibleTo($user)->orderBy('name')->pluck('name')->all();
    }

    public function test_users_get_uuid7_keys_and_hidden_secrets(): void
    {
        $user = User::factory()->create(['two_factor_secret' => 'secret']);

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertSame('7', $user->id[14]); // version nibble
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertFalse($user->isAdmin());
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
    }

    public function test_user_sees_only_resources_granted_to_their_teams_across_two_teams(): void
    {
        $user = User::factory()->create();
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();
        $team1->addMember($user, Role::Viewer);
        $team2->addMember($user, Role::Operator);
        $team1->grant($this->a);
        $team2->grant($this->a);
        $team2->grant($this->b);

        $this->assertSame(['a', 'b'], $this->visibleNames($user));
    }

    public function test_role_for_is_the_highest_role_across_teams_holding_a_grant(): void
    {
        $user = User::factory()->create();
        $viewers = Team::factory()->create();
        $operators = Team::factory()->create();
        $viewers->addMember($user, Role::Viewer);
        $operators->addMember($user, Role::Operator);
        $viewers->grant($this->a);
        $operators->grant($this->a);
        $viewers->grant($this->c);

        $this->assertSame(Role::Operator, $user->roleFor($this->a));
        $this->assertSame(Role::Viewer, $user->roleFor($this->c));
        $this->assertNull($user->roleFor($this->b));
    }

    public function test_admin_sees_everything_and_is_admin_everywhere(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertSame(['a', 'b', 'c'], $this->visibleNames($admin));
        $this->assertSame(Role::Admin, $admin->roleFor($this->b));
    }

    public function test_user_without_teams_or_with_ungranted_teams_sees_nothing(): void
    {
        $loner = User::factory()->create();
        $member = User::factory()->create();
        Team::factory()->create()->addMember($member, Role::Admin);

        $this->assertSame([], $this->visibleNames($loner));
        $this->assertSame([], $this->visibleNames($member));
        $this->assertNull($member->roleFor($this->a));
    }

    public function test_grants_do_not_leak_to_non_members(): void
    {
        $team = Team::factory()->create();
        $team->grant($this->a);
        $outsider = User::factory()->create();

        $this->assertSame([], $this->visibleNames($outsider));
    }

    public function test_deleted_teams_and_revoked_grants_and_removed_members_lose_access(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, Role::Operator);
        $team->grant($this->a);
        $this->assertSame(['a'], $this->visibleNames($user));

        $team->revoke($this->a);
        $this->assertSame([], $this->visibleNames($user));

        $team->grant($this->a);
        $team->members()->detach($user);
        $this->assertSame([], $this->visibleNames($user));

        $team->addMember($user, Role::Operator);
        $this->assertSame(['a'], $this->visibleNames($user));
        $team->delete(); // soft delete
        $this->assertSame([], $this->visibleNames($user));
        $this->assertNull($user->roleFor($this->a));
    }

    public function test_grants_are_idempotent_and_unique(): void
    {
        $team = Team::factory()->create();
        $team->grant($this->a);
        $team->grant($this->a);

        $this->assertSame(1, $team->grants()->count());

        $this->expectException(QueryException::class);
        DB::table('team_grants')->insert([
            'id' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'grantable_type' => 'widget',
            'grantable_id' => $this->a->id,
        ]);
    }

    public function test_database_rejects_unknown_roles(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'superuser']);
    }

    public function test_grant_types_are_scoped_so_a_pool_grant_does_not_expose_a_same_id_installation(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, Role::Viewer);
        DB::table('team_grants')->insert([
            'id' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'grantable_type' => 'pool',
            'grantable_id' => $this->a->id,
        ]);

        $this->assertSame([], $this->visibleNames($user)); // widgets are type "widget"
    }
}
