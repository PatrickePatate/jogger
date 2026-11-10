<?php

declare(strict_types=1);

namespace Tests\Feature\Pools;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Actions\ProvisionRunner;
use App\Livewire\Pools\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ToolCacheTest extends TestCase
{
    use RefreshDatabase;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeGithubGateway::install();
        $this->installation = Installation::factory()->create();
        Host::factory()->create(['status' => HostStatus::Online]);
    }

    /**
     * @return array<string, mixed>
     */
    private function startPayload(RunnerPool $pool): array
    {
        $runner = app(ProvisionRunner::class)($pool);

        return HostCommand::query()->where('type', 'runner.start')->get()
            ->firstOrFail(fn (HostCommand $c) => $c->payload['runner_id'] === $runner?->id)->payload;
    }

    public function test_runners_of_a_pool_share_one_volume_when_the_option_is_on(): void
    {
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'persist_tool_cache' => true]);

        $first = $this->startPayload($pool);
        $second = $this->startPayload($pool);

        $this->assertSame("jogger-toolcache-{$pool->id}", $first['tool_cache_volume']);
        $this->assertSame($first['tool_cache_volume'], $second['tool_cache_volume']);
    }

    public function test_no_volume_when_the_option_is_off(): void
    {
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'persist_tool_cache' => false]);

        $this->assertArrayNotHasKey('tool_cache_volume', $this->startPayload($pool));
    }

    public function test_two_pools_never_share_a_volume(): void
    {
        $a = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'persist_tool_cache' => true]);
        $b = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'persist_tool_cache' => true]);

        $this->assertNotSame($this->startPayload($a)['tool_cache_volume'], $this->startPayload($b)['tool_cache_volume']);
    }

    public function test_the_pool_form_saves_the_option(): void
    {
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'persist_tool_cache' => false]);

        Livewire::actingAs(User::factory()->admin()->create())->test(Form::class, ['pool' => $pool->id])
            ->assertSet('persist_tool_cache', false)
            ->set('persist_tool_cache', true)
            ->call('save')->assertHasNoErrors();

        $this->assertTrue($pool->fresh()->persist_tool_cache);
    }

    public function test_host_prune_carries_the_build_cache_cap_to_reachable_hosts_only(): void
    {
        Host::factory()->create(['status' => HostStatus::Offline]);
        config(['jogger.build_cache_max_gb' => 12.5]);

        $this->artisan('jogger:prune-hosts')->assertSuccessful();

        $commands = HostCommand::query()->where('type', 'host.prune')->get();
        $this->assertCount(1, $commands);
        $this->assertEquals(12.5, $commands[0]->payload['max_build_cache_gb']);
    }
}
