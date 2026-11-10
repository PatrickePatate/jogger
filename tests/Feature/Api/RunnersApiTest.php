<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\Actions\CreateApiToken;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunnersApiTest extends TestCase
{
    use RefreshDatabase;

    private RunnerPool $pool;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeGithubGateway::install();
        $this->pool = RunnerPool::factory()->create(['installation_id' => Installation::factory()->create()->id]);
        $this->host = Host::factory()->create(['name' => 'build-01']);
    }

    /**
     * @return array<string, string>
     */
    private function token(Role $role, bool $granted = true): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, $role);
        if ($granted) {
            $team->grant($this->pool->installation);
        }
        [$plain] = app(CreateApiToken::class)($user, 'test', $role);

        return ['Authorization' => "Bearer {$plain}"];
    }

    public function test_runners_are_listed_within_visibility(): void
    {
        $idle = Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $this->host->id, 'state' => RunnerState::Idle]);
        Runner::factory()->create(['pool_id' => $this->pool->id, 'state' => RunnerState::Gone]);
        Runner::factory()->create(['state' => RunnerState::Idle]);

        $this->getJson('/api/v1/runners', $this->token(Role::Viewer))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $idle->id)->assertJsonPath('data.0.state', 'idle');
        $this->getJson('/api/v1/runners?state=gone', $this->token(Role::Viewer))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/runners', $this->token(Role::Viewer, granted: false))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/runners?state=nope', $this->token(Role::Viewer))->assertStatus(422);
    }

    public function test_hosts_are_listed_without_revoked_ones_or_credentials(): void
    {
        Host::factory()->revoked()->create(['name' => 'old']);

        $response = $this->getJson('/api/v1/hosts', $this->token(Role::Viewer, granted: false))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'build-01')->assertJsonPath('data.0.status', 'online');
        $this->assertArrayNotHasKey('credential_hash', $response->json('data.0'));
    }

    public function test_viewers_get_403_on_mutations_and_operators_can_terminate(): void
    {
        $idle = Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $this->host->id, 'state' => RunnerState::Idle]);
        $busy = Runner::factory()->create(['pool_id' => $this->pool->id, 'host_id' => $this->host->id, 'state' => RunnerState::Busy]);

        $this->deleteJson("/api/v1/runners/{$idle->id}", [], $this->token(Role::Viewer))->assertForbidden();
        $this->assertSame(RunnerState::Idle, $idle->refresh()->state);
        // An operator elsewhere cannot even see it.
        $this->deleteJson("/api/v1/runners/{$idle->id}", [], $this->token(Role::Operator, granted: false))->assertNotFound();

        $operator = $this->token(Role::Operator);
        $this->deleteJson("/api/v1/runners/{$idle->id}", [], $operator)->assertOk()->assertJsonPath('data.state', 'terminating');
        $this->deleteJson("/api/v1/runners/{$busy->id}", [], $operator)->assertStatus(409);
        $this->deleteJson("/api/v1/runners/{$busy->id}?force=true", [], $operator)->assertOk()->assertJsonPath('data.state', 'terminating');
    }
}
