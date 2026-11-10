<?php

declare(strict_types=1);

namespace Tests\Feature\Pools;

use App\Domain\Access\Actions\CreateApiToken;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Pools\Actions\CreatePool;
use App\Domain\Pools\Enums\PoolStatus;
use App\Domain\Pools\Models\RunnerPool;
use App\Livewire\Pools\Form;
use App\Livewire\Pools\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PoolsTest extends TestCase
{
    use RefreshDatabase;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installation = Installation::factory()->create(['account_login' => 'acme']);
        Repository::factory()->create(['installation_id' => $this->installation->id, 'full_name' => 'acme/api', 'private' => true]);
        Repository::factory()->create(['installation_id' => $this->installation->id, 'full_name' => 'acme/site', 'private' => false]);
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function input(array $over = []): array
    {
        return $over + [
            'installation_id' => $this->installation->id, 'name' => 'linux', 'scope' => 'repository', 'scope_target' => 'acme/api',
            'labels' => ['self-hosted', 'linux', 'x64'], 'image' => 'ghcr.io/acme/runner:1.2', 'min_idle' => 1, 'max_runners' => 3,
        ];
    }

    private function member(Role $role, ?Installation $installation = null): User
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, $role);
        $team->grant($installation ?? $this->installation);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function token(User $user, Role $role): array
    {
        [$plain] = app(CreateApiToken::class)($user, 'test', $role);

        return ['Authorization' => "Bearer {$plain}"];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, string>>
     */
    private function errors(array $input): array
    {
        try {
            app(CreatePool::class)($input);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    public function test_validation_rules(): void
    {
        $this->assertArrayHasKey('max_runners', $this->errors($this->input(['min_idle' => 4, 'max_runners' => 3])));
        $this->assertArrayHasKey('labels', $this->errors($this->input(['labels' => []])));
        $this->assertArrayHasKey('labels.1', $this->errors($this->input(['labels' => ['ok', 'not ok!']])));
        $this->assertArrayHasKey('labels.0', $this->errors($this->input(['labels' => [str_repeat('a', 65)]])));
        $this->assertArrayHasKey('image', $this->errors($this->input(['image' => 'bad image; rm -rf'])));
        $this->assertArrayHasKey('scope_target', $this->errors($this->input(['scope_target' => 'other/repo'])));
        $this->assertArrayHasKey('scope_target', $this->errors($this->input(['scope' => 'organization', 'scope_target' => 'not-acme'])));

        $this->assertSame([], $this->errors($this->input()));
        $this->assertSame([], $this->errors($this->input(['name' => 'org', 'scope' => 'organization', 'scope_target' => 'acme', 'image' => 'localhost:5000/runner@sha256:'.str_repeat('a', 64)])));
        $this->assertArrayHasKey('name', $this->errors($this->input()));
        $this->assertSame(['self-hosted', 'linux', 'x64'], RunnerPool::query()->where('name', 'linux')->firstOrFail()->labels);
    }

    public function test_a_public_repository_needs_explicit_confirmation(): void
    {
        $this->assertArrayHasKey('confirm_public', $this->errors($this->input(['scope_target' => 'acme/site'])));
        $this->assertSame(0, RunnerPool::query()->count());

        $this->assertSame([], $this->errors($this->input(['scope_target' => 'acme/site', 'confirm_public' => true])));

        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Form::class)
            ->set('name', 'web')->set('scope_target', 'acme/site')
            ->assertSee('Public repository')
            ->call('save')->assertHasErrors('confirm_public')
            ->set('confirm_public', true)
            ->call('save')->assertHasNoErrors()->assertRedirect(route('pools.index'));
        $this->assertTrue(RunnerPool::query()->where('name', 'web')->exists());
    }

    public function test_the_form_validates_live_and_edits(): void
    {
        $admin = User::factory()->admin()->create();
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id]);

        Livewire::actingAs($admin)->test(Form::class, ['pool' => $pool->id])
            ->set('labels', 'bad label!!, x')->assertHasErrors('labels')
            ->set('labels', 'gpu, linux')->assertHasNoErrors('labels')
            ->set('max_runners', 9)
            ->call('save')->assertHasNoErrors()->assertRedirect(route('pools.index'));

        $pool->refresh();
        $this->assertSame(['gpu', 'linux'], $pool->labels);
        $this->assertSame(9, $pool->max_runners);
    }

    public function test_pool_list_is_filtered_by_visibility(): void
    {
        $mine = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'name' => 'mine-pool']);
        $other = RunnerPool::factory()->create(['name' => 'other-pool']);
        $granted = RunnerPool::factory()->create(['name' => 'granted-pool']);

        $viewer = $this->member(Role::Viewer);
        $viewer->teams()->firstOrFail()->grant($granted);

        Livewire::actingAs($viewer)->test(Index::class)
            ->assertSee('mine-pool')->assertSee('granted-pool')->assertDontSee('other-pool')
            ->assertDontSee('New pool')
            ->call('pause', $mine->id)->assertForbidden();

        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertSee('No pools yet');
        Livewire::actingAs($viewer)->test(Form::class, ['pool' => $mine->id])->assertForbidden();

        $this->getJson('/api/v1/pools', $this->token($viewer, Role::Viewer))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonMissing(['name' => 'other-pool']);
        $this->getJson("/api/v1/pools/{$other->id}", $this->token($viewer, Role::Viewer))->assertNotFound();
    }

    public function test_pause_and_resume(): void
    {
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id]);
        $operator = $this->member(Role::Operator);

        Livewire::actingAs($operator)->test(Index::class)->call('pause', $pool->id)->assertSee('Paused')->assertSee('Resume');
        $this->assertSame(PoolStatus::Paused, $pool->refresh()->status);
        Livewire::actingAs($operator)->test(Index::class)->call('resume', $pool->id);
        $this->assertSame(PoolStatus::Active, $pool->refresh()->status);
    }

    public function test_api_create_and_patch_respect_roles(): void
    {
        $operator = $this->member(Role::Operator);
        $viewer = $this->member(Role::Viewer);

        $id = $this->postJson('/api/v1/pools', $this->input(), $this->token($operator, Role::Operator))
            ->assertCreated()->assertJsonPath('data.name', 'linux')->assertJsonPath('data.status', 'active')->json('data.id');

        $this->patchJson("/api/v1/pools/{$id}", ['status' => 'paused'], $this->token($operator, Role::Operator))
            ->assertOk()->assertJsonPath('data.status', 'paused');
        $this->patchJson("/api/v1/pools/{$id}", ['status' => 'active'], $this->token($viewer, Role::Viewer))->assertForbidden();
        // An operator token of someone who is only a viewer of this pool.
        $elsewhere = $this->member(Role::Operator, Installation::factory()->create());
        $elsewhere->teams()->firstOrFail()->grant(RunnerPool::query()->findOrFail($id));
        $this->patchJson("/api/v1/pools/{$id}", ['max_runners' => 50], $this->token($elsewhere, Role::Operator))->assertOk();

        $this->patchJson("/api/v1/pools/{$id}", ['min_idle' => 99], $this->token($operator, Role::Operator))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->patchJson("/api/v1/pools/{$id}", ['scope_target' => 'acme/site'], $this->token($operator, Role::Operator))->assertStatus(422);

        // Creating in an installation the caller cannot see is a validation error, not a leak.
        $this->postJson('/api/v1/pools', $this->input(['installation_id' => Installation::factory()->create()->id, 'name' => 'x']), $this->token($operator, Role::Operator))
            ->assertStatus(422);
    }
}
