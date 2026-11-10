<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Pools\Actions\MatchPool;
use App\Domain\Pools\Data\PoolMatch;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchPoolTest extends TestCase
{
    use RefreshDatabase;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $installation = Installation::factory()->create(['account_login' => 'acme']);
        $this->repository = Repository::factory()->create(['installation_id' => $installation->id, 'full_name' => 'acme/api']);
    }

    /**
     * @param  list<string>  $labels
     * @param  array<string, mixed>  $over
     */
    private function pool(string $name, array $labels, array $over = []): RunnerPool
    {
        return RunnerPool::factory()->create($over + [
            'installation_id' => $this->repository->installation_id, 'name' => $name, 'labels' => $labels,
        ]);
    }

    /**
     * @param  list<string>  $labels
     */
    private function match(array $labels = ['self-hosted', 'linux', 'x64']): PoolMatch
    {
        return app(MatchPool::class)($this->repository, $labels);
    }

    public function test_superset_matches_and_a_missing_label_does_not(): void
    {
        $this->pool('no-arch', ['self-hosted', 'linux']);
        $this->assertFalse($this->match()->matched());

        $pool = $this->pool('full', ['self-hosted', 'linux', 'x64', 'gpu']);
        $this->assertSame($pool->id, $this->match()->pool?->id);
        $this->assertSame($pool->id, $this->match(['Self-Hosted', 'LINUX', 'X64'])->pool?->id);
    }

    public function test_more_specific_pool_wins_then_lower_utilization(): void
    {
        $wide = $this->pool('wide', ['self-hosted', 'linux', 'x64', 'gpu', 'big']);
        $exact = $this->pool('exact', ['self-hosted', 'linux', 'x64']);
        $this->assertSame($exact->id, $this->match()->pool?->id);

        $twin = $this->pool('twin', ['self-hosted', 'linux', 'x64']);
        Runner::factory()->count(2)->create(['pool_id' => $exact->id, 'state' => RunnerState::Busy]);
        $this->assertSame($twin->id, $this->match()->pool?->id);

        // Finished runners do not count as utilization.
        Runner::factory()->count(3)->create(['pool_id' => $twin->id, 'state' => RunnerState::Gone]);
        $this->assertSame($twin->id, $this->match()->pool?->id);
        $this->assertNotSame($wide->id, $this->match()->pool?->id);
    }

    public function test_scope_decides_which_pools_are_candidates(): void
    {
        $other = Installation::factory()->create(['account_login' => 'other']);
        RunnerPool::factory()->create(['installation_id' => $other->id, 'scope_target' => 'other']);
        $this->pool('other-repo', ['self-hosted', 'linux', 'x64'], ['scope' => 'repository', 'scope_target' => 'acme/site']);
        $this->assertFalse($this->match()->matched());

        $repo = $this->pool('this-repo', ['self-hosted', 'linux', 'x64'], ['scope' => 'repository', 'scope_target' => 'acme/api']);
        $this->assertSame($repo->id, $this->match()->pool?->id);
    }

    public function test_no_match_gives_a_reason_listing_the_labels(): void
    {
        $result = $this->match(['self-hosted', 'windows']);

        $this->assertNull($result->pool);
        $this->assertSame(PoolMatch::NO_MATCHING_POOL, $result->code);
        $this->assertSame('NO_MATCHING_POOL: no pool has all of these labels: self-hosted, windows', $result->reason());
        $this->assertLessThanOrEqual(200, mb_strlen((string) $this->match(array_fill(0, 30, 'a-very-long-label-name'))->reason()));

        $this->pool('paused', ['self-hosted', 'windows'], ['status' => 'paused']);
        $this->assertSame(PoolMatch::POOL_PAUSED, $this->match(['self-hosted', 'windows'])->code);

        $this->assertTrue(MatchPool::wantsSelfHosted(['Self-Hosted', 'linux']));
        $this->assertFalse(MatchPool::wantsSelfHosted(['ubuntu-latest']));
    }
}
