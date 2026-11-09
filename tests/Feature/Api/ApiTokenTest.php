<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\Actions\CreateApiToken;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\PersonalAccessToken;
use App\Domain\Access\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: PersonalAccessToken, 2: User}
     */
    private function issue(Role $role = Role::Viewer, ?User $user = null, ?\DateTimeInterface $expires = null): array
    {
        $user ??= User::factory()->admin()->create();
        [$plain, $token] = app(CreateApiToken::class)($user, 'Test token', $role, $expires ? Carbon::instance($expires) : null);

        return [$plain, $token, $user];
    }

    public function test_the_database_stores_only_a_hash_of_the_token(): void
    {
        [$plain, $token] = $this->issue();

        $this->assertStringStartsWith('jog_', $plain);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);

        // The plain token appears in no column of any row.
        $row = json_encode((array) DB::table('personal_access_tokens')->first());
        $this->assertStringNotContainsString($plain, $row);
        $this->assertSame(0, DB::table('audit_logs')->where('metadata', 'like', '%'.$plain.'%')->count());
    }

    public function test_me_returns_the_token_owner(): void
    {
        [$plain, $token, $user] = $this->issue(Role::Operator);

        $this->withToken($plain)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.token.role', 'operator')
            ->assertJsonPath('data.token.id', $token->id)
            ->assertJsonStructure(['meta' => ['request_id']]);
    }

    public function test_missing_malformed_and_unknown_tokens_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withToken('jog_'.str_repeat('x', 40))->getJson('/api/v1/me')->assertStatus(401);
        $this->withToken('not-a-jogger-token')->getJson('/api/v1/me')->assertStatus(401);
        $this->withHeader('Authorization', 'Basic '.base64_encode('a:b'))->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_expired_tokens_and_deleted_owners_are_refused(): void
    {
        [$plain, $token] = $this->issue();
        $token->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(401);

        [$plain2, , $owner] = $this->issue();
        $owner->delete();
        $this->withToken($plain2)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_last_used_is_recorded_but_not_on_every_call(): void
    {
        [$plain, $token] = $this->issue();
        $this->assertNull($token->last_used_at);

        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        $first = $token->refresh()->last_used_at;
        $this->assertNotNull($first);

        $this->travel(10)->seconds();
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        $this->assertEquals($first, $token->refresh()->last_used_at); // within a minute: no write

        $this->travel(2)->minutes();
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        $this->assertTrue($token->refresh()->last_used_at->gt($first));
    }

    public function test_a_viewer_token_is_forbidden_on_an_operator_endpoint(): void
    {
        Route::middleware(['api.token', 'api.role:operator'])->get('/api/v1/_test/operator', fn () => response()->json(['ok' => true]));

        [$viewer] = $this->issue(Role::Viewer);
        [$operator] = $this->issue(Role::Operator);
        [$admin] = $this->issue(Role::Admin);

        $this->withToken($viewer)->getJson('/api/v1/_test/operator')
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->withToken($operator)->getJson('/api/v1/_test/operator')->assertOk();
        $this->withToken($admin)->getJson('/api/v1/_test/operator')->assertOk();
    }

    public function test_the_121st_request_in_a_minute_is_throttled_with_retry_after(): void
    {
        [$plain] = $this->issue();

        foreach (range(1, 120) as $i) {
            $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        }

        $this->withToken($plain)->getJson('/api/v1/me')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        // Another token has its own bucket.
        [$other] = $this->issue();
        $this->withToken($other)->getJson('/api/v1/me')->assertOk();
    }

    public function test_tokens_cannot_exceed_the_owners_own_access(): void
    {
        $nobody = User::factory()->create();
        $viewerOnTeam = User::factory()->create();
        Team::factory()->create()->addMember($viewerOnTeam, Role::Viewer);
        $operator = User::factory()->create();
        Team::factory()->create()->addMember($operator, Role::Operator);

        $this->issue(Role::Viewer, $nobody); // anyone may create a viewer token
        $this->issue(Role::Operator, $operator);

        foreach ([[$nobody, Role::Operator], [$viewerOnTeam, Role::Operator], [$operator, Role::Admin]] as [$user, $role]) {
            try {
                app(CreateApiToken::class)($user, 'Too much', $role);
                $this->fail("{$role->value} token should have been refused");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(Role::Admin, User::factory()->admin()->create()->maxRole());
        $this->assertNull($nobody->maxRole());
    }

    public function test_the_database_rejects_an_unknown_token_role(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('personal_access_tokens')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'name' => 'x',
            'token_hash' => str_repeat('a', 64), 'role' => 'root',
        ]);
    }
}
