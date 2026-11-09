<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Actions\CreateApiToken;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Access\Models\PersonalAccessToken;
use App\Livewire\Settings\Tokens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TokensPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_requires_login_and_renders(): void
    {
        User::factory()->create();
        $this->get('/settings/tokens')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get('/settings/tokens')->assertOk()->assertSee('Create a token')->assertSee('No tokens yet');
    }

    public function test_creating_a_token_shows_it_once_and_stores_only_the_hash(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Tokens::class)
            ->set('name', 'CI deploy')->set('role', 'viewer')->set('expiresInDays', 30)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-testid="new-token"')
            ->assertSet('name', '');

        $plain = $component->get('newToken');
        $this->assertStringStartsWith('jog_', $plain);
        $this->assertDatabaseHas('personal_access_tokens', ['user_id' => $user->id, 'name' => 'CI deploy', 'token_hash' => hash('sha256', $plain)]);
        $this->assertTrue(PersonalAccessToken::firstOrFail()->expires_at->isFuture());

        $component->call('dismissToken')->assertSet('newToken', null)->assertDontSee($plain);
        // A fresh page load never shows it again.
        Livewire::actingAs($user)->test(Tokens::class)->assertSet('newToken', null)->assertDontSee($plain)->assertSee('CI deploy');
    }

    public function test_validation(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Tokens::class)
            ->set('name', '')->call('create')->assertHasErrors('name')
            ->set('name', 'ok')->set('role', 'root')->call('create')->assertHasErrors('role')
            ->set('role', 'viewer')->set('expiresInDays', 7)->call('create')->assertHasErrors('expiresInDays');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_role_menu_and_server_enforce_the_users_ceiling(): void
    {
        $user = User::factory()->create(); // no team: viewer tokens only

        Livewire::actingAs($user)->test(Tokens::class)
            ->assertSee('Viewer')->assertDontSee('>Operator<', false)->assertDontSee('>Admin<', false)
            ->set('name', 'sneaky')->set('role', 'admin')->call('create')
            ->assertHasErrors('role');

        $this->assertSame(0, PersonalAccessToken::count());

        Livewire::actingAs(User::factory()->admin()->create())->test(Tokens::class)->assertSee('Operator')->assertSee('Admin');
    }

    public function test_users_revoke_their_own_tokens_and_it_is_audited(): void
    {
        $user = User::factory()->create();
        [, $token] = app(CreateApiToken::class)($user, 'Old', Role::Viewer);

        Livewire::actingAs($user)->test(Tokens::class)->call('revoke', $token->id);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->assertTrue(AuditLog::where('action', 'token.revoked')->where('target_id', $token->id)->exists());
    }

    public function test_users_cannot_revoke_other_peoples_tokens_but_admins_can(): void
    {
        $owner = User::factory()->create();
        [, $token] = app(CreateApiToken::class)($owner, 'Theirs', Role::Viewer);

        Livewire::actingAs(User::factory()->create())->test(Tokens::class)->call('revoke', $token->id)->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);

        Livewire::actingAs(User::factory()->admin()->create())->test(Tokens::class)->call('revoke', $token->id);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_only_own_tokens_are_listed_and_expired_ones_are_flagged(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        app(CreateApiToken::class)($other, 'Not mine', Role::Viewer);
        [, $mine] = app(CreateApiToken::class)($me, 'Mine', Role::Viewer);
        $mine->forceFill(['expires_at' => now()->subDay()])->save();

        Livewire::actingAs($me)->test(Tokens::class)->assertSee('Mine')->assertDontSee('Not mine')->assertSee('Expired');
    }
}
