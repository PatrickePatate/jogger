<?php

declare(strict_types=1);

namespace Tests\Feature\Pools;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Actions\PickHost;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Hosts\Models\HostImage;
use App\Domain\Pools\Actions\CreatePool;
use App\Domain\Pools\Actions\UpdatePool;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Livewire\Pools\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ImagePullTest extends TestCase
{
    use RefreshDatabase;

    private Installation $installation;

    private Host $a;

    private Host $b;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->installation = Installation::factory()->create(['account_login' => 'acme']);
        Repository::factory()->create(['installation_id' => $this->installation->id, 'full_name' => 'acme/api', 'private' => true]);
        $this->a = Host::factory()->create(['name' => 'a', 'labels' => ['ssd'], 'cpu_used' => 0, 'memory_used_mb' => 8000, 'credential_hash' => Host::hashCredential('jha_a')]);
        $this->b = Host::factory()->create(['name' => 'b', 'cpu_used' => 0, 'memory_used_mb' => 0, 'credential_hash' => Host::hashCredential('jha_b')]);
        Host::factory()->create(['name' => 'off', 'status' => 'offline']);
    }

    private function pool(): RunnerPool
    {
        return app(CreatePool::class)([
            'installation_id' => $this->installation->id, 'name' => 'linux', 'scope' => 'repository', 'scope_target' => 'acme/api',
            'labels' => ['self-hosted', 'linux'], 'image' => 'ghcr.io/acme/runner:1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ack(string $credential, HostCommand $command, array $body): void
    {
        $this->withToken($credential)->getJson('/agent/v1/commands')->assertOk();
        $this->withToken($credential)->postJson("/agent/v1/commands/{$command->id}/ack", $body)->assertOk();
        $this->flushHeaders();
    }

    public function test_saving_a_pool_with_a_new_image_queues_pulls_on_allowed_hosts(): void
    {
        $pool = $this->pool();

        $commands = HostCommand::query()->where('type', 'image.pull')->get();
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $commands->pluck('host_id')->all());
        $this->assertSame(['image' => 'ghcr.io/acme/runner:1'], $commands->first()->payload);
        $this->assertSame(2, HostImage::query()->where('status', 'pending')->count());

        // Saving without touching the image pulls nothing; a new tag pulls again; host labels narrow the targets.
        app(UpdatePool::class)($pool, ['max_runners' => 9]);
        $this->assertSame(2, HostCommand::query()->count());
        app(UpdatePool::class)($pool, ['image' => 'ghcr.io/acme/runner:2', 'allowed_host_labels' => ['ssd']]);
        $new = HostCommand::query()->where('payload->image', 'ghcr.io/acme/runner:2')->get();
        $this->assertSame([$this->a->id], $new->pluck('host_id')->all());
    }

    public function test_pull_results_are_tracked_per_host_and_a_failure_blocks_nothing(): void
    {
        $pool = $this->pool();
        $running = Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $this->a->id, 'state' => RunnerState::Busy]);
        $toA = HostCommand::query()->where('host_id', $this->a->id)->sole();
        $toB = HostCommand::query()->where('host_id', $this->b->id)->sole();

        $this->ack('jha_a', $toA, ['ok' => false, 'result' => ['error' => 'manifest unknown']]);
        $this->ack('jha_b', $toB, ['ok' => true, 'result' => ['image' => 'ghcr.io/acme/runner:1']]);

        $this->assertSame(['failed', 'manifest unknown'], HostImage::query()->where('host_id', $this->a->id)->get(['status', 'error'])->map->only(['status', 'error'])->map(fn ($r) => array_values($r))->first());
        $ready = HostImage::query()->where('host_id', $this->b->id)->sole();
        $this->assertSame('ready', $ready->status);
        $this->assertNotNull($ready->pulled_at);
        $this->assertSame(RunnerState::Busy, $running->refresh()->state);

        Livewire::actingAs(User::factory()->admin()->create())->test(Form::class, ['pool' => $pool->id])
            ->assertSee('Image on hosts')->assertSee('manifest unknown')->assertSee('Ready')
            ->call('pullImage');
        // "Pull again" retries everywhere.
        $this->assertSame(4, HostCommand::query()->where('type', 'image.pull')->count());
        $this->assertSame(2, HostImage::query()->where('status', 'pending')->count());
    }

    public function test_placement_prefers_a_host_that_has_the_image_and_falls_back_otherwise(): void
    {
        $pool = RunnerPool::factory()->create(['installation_id' => $this->installation->id, 'image' => 'ghcr.io/acme/runner:1']);

        // Nobody has it: the emptiest host is used and pulls on demand.
        $this->assertSame($this->b->id, app(PickHost::class)($pool)?->id);

        HostImage::query()->create(['host_id' => $this->a->id, 'image' => $pool->image, 'status' => 'ready']);
        HostImage::query()->create(['host_id' => $this->b->id, 'image' => $pool->image, 'status' => 'failed', 'error' => 'x']);
        $this->assertSame($this->a->id, app(PickHost::class)($pool)?->id);

        // The warm host is full: fall back to the other one.
        $this->a->update(['memory_used_mb' => 16000]);
        $this->assertSame($this->b->id, app(PickHost::class)($pool)?->id);
    }
}
