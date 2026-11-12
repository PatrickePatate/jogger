<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Console\Commands\RotateAppKey;
use App\Domain\Cloud\Actions\TransitionCloudHost;
use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Contracts\CloudDriver;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Cloud\Testing\FakeCloudDriver;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class CloudFoundationTest extends TestCase
{
    use RefreshDatabase;

    /** The transition table of docs/CLOUD-HOSTS.md, written out independently of the enum. */
    private const ALLOWED = [
        'requested' => ['booting', 'failed', 'deleting'],
        'booting' => ['online', 'failed', 'deleting'],
        'online' => ['draining', 'deleting'],
        'draining' => ['deleting', 'online'],
        'deleting' => ['deleted', 'failed'],
        'failed' => ['deleting', 'deleted'],
        'deleted' => [],
    ];

    /**
     * @return iterable<string, array{CloudHostState, CloudHostState, bool}>
     */
    public static function pairs(): iterable
    {
        foreach (CloudHostState::cases() as $from) {
            foreach (CloudHostState::cases() as $to) {
                yield "{$from->value} -> {$to->value}" => [$from, $to, in_array($to->value, self::ALLOWED[$from->value], true)];
            }
        }
    }

    #[DataProvider('pairs')]
    public function test_state_machine_table(CloudHostState $from, CloudHostState $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
        $this->assertSame($allowed, in_array($from, $to->previous(), true));
    }

    public function test_terminal_states_and_labels(): void
    {
        $this->assertTrue(CloudHostState::Deleted->isTerminal());
        $this->assertTrue(CloudHostState::Failed->isTerminal());
        $this->assertFalse(CloudHostState::Online->isTerminal());
        $this->assertSame('Booting', CloudHostState::Booting->label());
    }

    public function test_allowed_transition_moves_the_host_and_stamps_the_change(): void
    {
        $host = Host::factory()->create(['cloud_state' => CloudHostState::Requested]);

        $moved = app(TransitionCloudHost::class)($host, CloudHostState::Booting, ['provider_server_id' => 'fake-1']);

        $this->assertTrue($moved);
        $this->assertSame(CloudHostState::Booting, $host->cloud_state);
        $this->assertSame('fake-1', $host->provider_server_id);
        $this->assertNotNull($host->cloud_state_changed_at);
    }

    public function test_refused_transition_changes_nothing(): void
    {
        $host = Host::factory()->create(['cloud_state' => CloudHostState::Online, 'provider_server_id' => 'fake-9']);

        $moved = app(TransitionCloudHost::class)($host, CloudHostState::Booting, ['provider_server_id' => 'other']);

        $this->assertFalse($moved);
        $this->assertSame(CloudHostState::Online, $host->fresh()->cloud_state);
        $this->assertSame('fake-9', $host->fresh()->provider_server_id);
    }

    public function test_stale_transition_loses_to_a_concurrent_one(): void
    {
        $host = Host::factory()->create(['cloud_state' => CloudHostState::Booting]);
        $stale = Host::query()->findOrFail($host->id);

        // Another worker already moved it on while this one still holds the old state.
        DB::table('hosts')->where('id', $host->id)->update(['cloud_state' => 'deleting']);

        $this->assertFalse(app(TransitionCloudHost::class)($stale, CloudHostState::Online));
        $this->assertSame(CloudHostState::Deleting, Host::query()->findOrFail($host->id)->cloud_state);
    }

    public function test_host_without_a_cloud_state_cannot_transition(): void
    {
        $host = Host::factory()->create();

        $this->assertNull($host->cloud_state);
        $this->assertFalse(app(TransitionCloudHost::class)($host, CloudHostState::Booting));
    }

    public function test_credentials_are_encrypted_at_rest_and_hidden(): void
    {
        $provider = CloudProvider::factory()->create(['credentials' => ['token' => 'plain-api-token-123']]);

        $raw = DB::table('cloud_providers')->where('id', $provider->id)->value('credentials');
        $this->assertStringNotContainsString('plain-api-token-123', $raw);
        $this->assertSame(['token' => 'plain-api-token-123'], CloudProvider::query()->findOrFail($provider->id)->credentials);
        $this->assertArrayNotHasKey('credentials', $provider->toArray());
    }

    public function test_templates_link_to_provider_pools_and_hosts(): void
    {
        $provider = CloudProvider::factory()->create();
        $template = HostTemplate::factory()->create(['cloud_provider_id' => $provider->id, 'labels' => ['gpu', 'x64']]);
        $pool = RunnerPool::factory()->create();
        $template->pools()->attach($pool);
        $host = Host::factory()->create(['host_template_id' => $template->id, 'ephemeral' => true]);

        $this->assertTrue($provider->templates->contains($template));
        $this->assertTrue($template->provider->is($provider));
        $this->assertTrue($template->pools->contains($pool));
        $this->assertTrue($pool->templates->contains($template));
        $this->assertTrue($template->hosts->contains($host));
        $this->assertTrue($host->template->is($template));
        $this->assertSame(['gpu', 'x64'], $template->labels);
        $this->assertTrue($host->ephemeral);
        $this->assertTrue($template->ipv4);
        $this->assertSame(1, $template->max_runners_per_host);
        $this->assertSame([], $template->options);
    }

    public function test_deleting_a_template_releases_its_hosts_and_detaches_its_pools(): void
    {
        $template = HostTemplate::factory()->create();
        $pool = RunnerPool::factory()->create();
        $template->pools()->attach($pool);
        $host = Host::factory()->create(['host_template_id' => $template->id]);

        $template->delete();

        $this->assertNull($host->fresh()->host_template_id);
        $this->assertTrue(RunnerPool::query()->whereKey($pool->id)->exists());
        $this->assertSame(0, DB::table('host_template_pool')->count());
    }

    public function test_deleting_a_provider_deletes_its_templates(): void
    {
        $provider = CloudProvider::factory()->create();
        $template = HostTemplate::factory()->create(['cloud_provider_id' => $provider->id]);

        $provider->delete();

        $this->assertFalse(HostTemplate::query()->whereKey($template->id)->exists());
    }

    public function test_manager_resolves_fake_and_rejects_unknown_drivers(): void
    {
        $manager = app(CloudManager::class);
        $provider = CloudProvider::factory()->create(['driver' => 'fake']);

        $this->assertSame(['fake'], $manager->drivers());
        $this->assertInstanceOf(FakeCloudDriver::class, $manager->driver('fake'));
        $this->assertSame(app(FakeCloudDriver::class), $manager->for($provider));

        $this->expectException(InvalidArgumentException::class);
        $manager->driver('nope');
    }

    public function test_manager_rejects_a_configured_class_that_is_not_a_driver(): void
    {
        config(['jogger.cloud.drivers' => ['broken' => stdClass::class]]);

        $this->expectException(InvalidArgumentException::class);
        app(CloudManager::class)->driver('broken');
    }

    public function test_fake_driver_creates_gets_lists_and_deletes(): void
    {
        $provider = CloudProvider::factory()->create();
        $fake = app(FakeCloudDriver::class);
        $spec = $this->spec([CloudDriver::MANAGED_BY_LABEL => 'jogger', CloudDriver::INSTANCE_LABEL => 'inst-a']);

        $server = $fake->create($provider, $spec);

        $this->assertSame('fake-1', $server->id);
        $this->assertSame('running', $server->status);
        $this->assertNotNull($server->ipv4);
        $this->assertSame($server, $fake->get($provider, 'fake-1'));
        $this->assertCount(1, $fake->listManaged($provider, 'inst-a'));
        $this->assertCount(0, $fake->listManaged($provider, 'inst-b'));

        $fake->delete($provider, 'fake-1');
        $fake->delete($provider, 'fake-1');

        $this->assertNull($fake->get($provider, 'fake-1'));
        $this->assertSame([], $fake->servers());
        $this->assertSame([$spec], $fake->created);
        $this->assertSame(['fake-1', 'fake-1'], $fake->deleted);
    }

    public function test_fake_driver_fails_only_the_next_create(): void
    {
        $provider = CloudProvider::factory()->create();
        $fake = app(FakeCloudDriver::class);
        $fake->failNextCreate(CloudException::quotaReached('no more servers'));

        try {
            $fake->create($provider, $this->spec());
            $this->fail('create() did not throw');
        } catch (CloudException $e) {
            $this->assertSame('quota', $e->reason);
        }

        $this->assertSame('fake-1', $fake->create($provider, $this->spec())->id);
    }

    public function test_cloud_exception_reasons(): void
    {
        $this->assertSame('credentials', CloudException::invalidCredentials('bad token')->reason);
        $api = CloudException::api('service unavailable', 503);
        $this->assertSame('api', $api->reason);
        $this->assertSame(503, $api->getCode());
    }

    public function test_key_rotation_still_decrypts_provider_credentials(): void
    {
        Model::encryptUsing(null); // another test may have left its own encrypter behind

        $provider = CloudProvider::factory()->create(['credentials' => ['token' => 'rotate-me']]);
        $before = DB::table('cloud_providers')->where('id', $provider->id)->value('credentials');
        $newKey = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));

        $this->artisan('jogger:rotate-key', ['--new-key' => $newKey])->assertSuccessful();

        $this->assertNotSame($before, DB::table('cloud_providers')->where('id', $provider->id)->value('credentials'));
        $this->assertArrayHasKey('cloud_providers', RotateAppKey::COLUMNS);

        config(['app.key' => $newKey]);
        Crypt::swap(new Encrypter(base64_decode(substr($newKey, 7)), (string) config('app.cipher')));
        Model::encryptUsing(Crypt::getFacadeRoot());

        try {
            $this->assertSame(['token' => 'rotate-me'], CloudProvider::query()->findOrFail($provider->id)->credentials);
        } finally {
            // The static encrypter outlives the test; later tests must go back to the application's own.
            Model::encryptUsing(null);
        }
    }

    /** @param  array<string, string>  $labels */
    private function spec(array $labels = []): ServerSpec
    {
        return new ServerSpec(
            name: 'jogger-test',
            serverType: 'cx22',
            location: 'fsn1',
            image: 'ubuntu-24.04',
            userData: "#cloud-config\n",
            ipv4: true,
            labels: $labels,
            options: [],
        );
    }
}
