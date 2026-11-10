<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Domain\Hosts\Actions\DispatchHostCommand;
use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Enums\CommandStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class CommandsTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->host = Host::factory()->create();
        $this->credential = Host::CREDENTIAL_PREFIX.'one';
        $this->host->update(['credential_hash' => Host::hashCredential($this->credential)]);
    }

    private function send(string $type = 'runner.start', ?Host $host = null): HostCommand
    {
        return app(HostDriver::class)->send($host ?? $this->host, $type, ['n' => $type]);
    }

    private function other(): Host
    {
        return Host::factory()->create(['credential_hash' => Host::hashCredential('jha_two')]);
    }

    public function test_commands_are_returned_oldest_first_and_marked_sent(): void
    {
        $a = $this->send('a');
        $this->travel(1)->seconds();
        $b = $this->send('b');
        $this->send('theirs', $this->other());

        $response = $this->withToken($this->credential)->getJson('/agent/v1/commands')->assertOk();

        $this->assertSame([$a->id, $b->id], array_column($response->json('commands'), 'id'));
        $this->assertSame(['n' => 'a'], $response->json('commands.0.payload'));
        $this->assertSame(CommandStatus::Sent, $a->refresh()->status);
        $this->assertSame(1, $a->attempts);
        $this->assertNotNull($a->sent_at);

        $this->withToken($this->credential)->getJson('/agent/v1/commands')->assertOk()->assertJsonCount(0, 'commands');
    }

    public function test_commands_scheduled_for_later_are_not_returned_yet(): void
    {
        $this->send()->update(['available_at' => now()->addMinute()]);

        $this->withToken($this->credential)->getJson('/agent/v1/commands')->assertJsonCount(0, 'commands');
    }

    public function test_an_ack_completes_the_command_and_stores_the_result(): void
    {
        $command = $this->send();
        $this->withToken($this->credential)->getJson('/agent/v1/commands');

        $this->withToken($this->credential)->postJson("/agent/v1/commands/{$command->id}/ack", ['ok' => true, 'result' => ['container_id' => 'abc']])
            ->assertOk()->assertJsonPath('status', 'acked');

        $command->refresh();
        $this->assertSame(CommandStatus::Acked, $command->status);
        $this->assertSame(['container_id' => 'abc'], $command->result);
        $this->assertNotNull($command->acked_at);
    }

    public function test_a_failed_ack_is_recorded_and_a_repeated_ack_changes_nothing(): void
    {
        $command = $this->send();
        $url = "/agent/v1/commands/{$command->id}/ack";

        $this->withToken($this->credential)->postJson($url, ['ok' => false, 'result' => ['error' => 'no space']])->assertJsonPath('status', 'failed');
        $this->withToken($this->credential)->postJson($url, ['ok' => true])->assertOk()->assertJsonPath('status', 'failed');

        $this->assertSame(['error' => 'no space'], $command->refresh()->result);
    }

    public function test_unknown_foreign_and_malformed_command_ids_are_404(): void
    {
        $foreign = $this->send('x', $this->other());

        $this->withToken($this->credential)->postJson("/agent/v1/commands/{$foreign->id}/ack", ['ok' => true])->assertNotFound();
        $this->withToken($this->credential)->postJson('/agent/v1/commands/'.fake()->uuid().'/ack', ['ok' => true])->assertNotFound();
        $this->withToken($this->credential)->postJson('/agent/v1/commands/not-a-uuid/ack', ['ok' => true])->assertNotFound();
        $this->assertSame(CommandStatus::Pending, $foreign->refresh()->status);
    }

    public function test_ack_requires_the_ok_flag(): void
    {
        $command = $this->send();

        $this->withToken($this->credential)->postJson("/agent/v1/commands/{$command->id}/ack", [])->assertUnprocessable();
    }

    public function test_commands_need_a_valid_credential(): void
    {
        $this->getJson('/agent/v1/commands')->assertUnauthorized();
        $this->postJson('/agent/v1/commands/'.fake()->uuid().'/ack', ['ok' => true])->assertUnauthorized();
    }

    public function test_the_broadcast_hint_is_sent_only_after_the_transaction_commits(): void
    {
        try {
            DB::transaction(function () {
                $this->send();
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        Queue::assertNotPushed(BroadcastEvent::class);
        $this->assertSame(0, HostCommand::query()->count());

        DB::transaction(function () {
            $this->send();
            Queue::assertNotPushed(BroadcastEvent::class);
        });
        Queue::assertPushed(BroadcastEvent::class, 1);
    }

    public function test_the_hint_goes_to_the_hosts_private_channel(): void
    {
        $command = app(DispatchHostCommand::class)($this->host, 'x');

        Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($command) {
            $event = $job->event;

            return $event->broadcastOn()[0]->name === "private-host.{$this->host->id}"
                && $event->broadcastWith() === ['command_id' => $command->id];
        });
    }

    public function test_an_agent_can_only_subscribe_to_its_own_channel(): void
    {
        $auth = fn (string $channel) => $this->withToken($this->credential)
            ->postJson('/agent/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);

        $auth("private-host.{$this->host->id}")->assertOk()->assertJsonStructure(['auth']);
        $auth("private-host.{$this->other()->id}")->assertForbidden();
        $auth('private-hosts')->assertForbidden();
        $auth('private-jobs')->assertForbidden();

        $this->flushHeaders();
        $this->postJson('/agent/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-host.{$this->host->id}"])->assertUnauthorized();
    }

    public function test_browsers_cannot_join_host_channels(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-host.{$this->host->id}"])->assertForbidden();
    }

    public function test_unacked_commands_are_offered_again_after_30_seconds(): void
    {
        $command = $this->send();
        $this->withToken($this->credential)->getJson('/agent/v1/commands');
        Queue::fake();

        $this->travel(20)->seconds();
        $this->artisan('jogger:redeliver-commands')->assertSuccessful();
        $this->assertSame(CommandStatus::Sent, $command->refresh()->status);

        $this->travel(15)->seconds();
        $this->artisan('jogger:redeliver-commands')->assertSuccessful();
        $this->assertSame(CommandStatus::Pending, $command->refresh()->status);
        Queue::assertPushed(BroadcastEvent::class, 1);

        $this->withToken($this->credential)->getJson('/agent/v1/commands')->assertJsonCount(1, 'commands');
        $this->assertSame(2, $command->refresh()->attempts);
    }

    public function test_redelivery_stops_after_five_attempts(): void
    {
        $command = $this->send();

        for ($i = 1; $i <= 5; $i++) {
            $this->withToken($this->credential)->getJson('/agent/v1/commands')->assertJsonCount(1, 'commands');
            $this->assertSame($i, $command->refresh()->attempts);
            $this->travel(31)->seconds();
            $this->artisan('jogger:redeliver-commands')->assertSuccessful();
        }

        $command->refresh();
        $this->assertSame(CommandStatus::Failed, $command->status);
        $this->assertSame(5, $command->attempts);
        $this->withToken($this->credential)->getJson('/agent/v1/commands')->assertJsonCount(0, 'commands');
    }

    public function test_acked_commands_are_never_redelivered(): void
    {
        $command = $this->send();
        $this->withToken($this->credential)->getJson('/agent/v1/commands');
        $this->withToken($this->credential)->postJson("/agent/v1/commands/{$command->id}/ack", ['ok' => true]);

        $this->travel(5)->minutes();
        $this->artisan('jogger:redeliver-commands')->assertSuccessful();

        $this->assertSame(CommandStatus::Acked, $command->refresh()->status);
    }

    public function test_redelivery_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('jogger:redeliver-commands')->assertSuccessful();
    }
}
