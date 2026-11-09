<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Pinged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_broadcast_auth_is_forbidden(): void
    {
        $this->post('/broadcasting/auth', ['channel_name' => 'private-jobs', 'socket_id' => '123.456'])
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function channels(): array
    {
        return [
            'jobs' => ['private-jobs'],
            'repository' => ['private-repository.1'],
            'job' => ['private-job.1'],
            'host' => ['private-host.1'],
            'pool' => ['private-pool.1'],
        ];
    }

    #[DataProvider('channels')]
    public function test_placeholder_channels_deny_everyone_until_policies_exist(string $channel): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '123.456'])
            ->assertForbidden();
    }

    public function test_ping_endpoint_dispatches_a_broadcast_event_in_local(): void
    {
        $this->app['env'] = 'local';
        Event::fake([Pinged::class]);

        $this->withSession(['_token' => 't'])->post('/_ui/ping', ['_token' => 't'])->assertNoContent();

        Event::assertDispatched(Pinged::class);
    }

    public function test_ping_endpoint_is_hidden_outside_local(): void
    {
        $this->app['env'] = 'production';
        Event::fake([Pinged::class]);

        $this->withSession(['_token' => 't'])->post('/_ui/ping', ['_token' => 't'])->assertNotFound();

        Event::assertNotDispatched(Pinged::class);
    }

    public function test_pinged_event_broadcasts_on_the_public_dev_channel(): void
    {
        $event = new Pinged('hello');

        $this->assertSame('dev.ping', $event->broadcastOn()[0]->name);
        $this->assertSame('Pinged', $event->broadcastAs());
    }
}
