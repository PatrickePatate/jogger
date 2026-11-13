<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Drivers\OpenStackDriver;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenStackDriverTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-cred-s3cr3t';

    private const TOKEN = 'gAAAA-token-value';

    private const AUTH = 'https://auth.example.net/v3';

    private const NOVA = 'https://compute.gra11.example.net/v2.1';

    private const ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private OpenStackDriver $driver;

    private CloudProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->driver = new OpenStackDriver;
        $this->provider = new CloudProvider([
            'name' => 'ovh', 'driver' => 'openstack',
            'credentials' => ['auth_url' => self::AUTH, 'application_credential_id' => 'cred-1', 'token' => self::SECRET],
        ]);
    }

    public function test_is_registered_as_a_driver(): void
    {
        $this->assertInstanceOf(OpenStackDriver::class, app(CloudManager::class)->driver('openstack'));
    }

    public function test_authenticates_with_the_application_credential(): void
    {
        $this->fakeKeystone();

        $this->driver->validateCredentials($this->provider->credentials);

        $request = Http::recorded()->first()[0];
        $this->assertSame(self::AUTH.'/auth/tokens', $request->url());
        $this->assertSame(
            ['methods' => ['application_credential'], 'application_credential' => ['id' => 'cred-1', 'secret' => self::SECRET]],
            $request->data()['auth']['identity'],
        );
    }

    public function test_a_plain_http_auth_url_is_refused_before_any_request(): void
    {
        $caught = $this->catchCloudException(fn () => $this->driver->validateCredentials(['auth_url' => 'http://auth.example.net/v3', 'application_credential_id' => 'a', 'token' => 'b']));

        $this->assertSame('credentials', $caught->reason);
        Http::assertNothingSent();
    }

    public function test_keystone_rejection_is_invalid_credentials(): void
    {
        Http::fake([self::AUTH.'/auth/tokens' => Http::response(['error' => ['message' => 'bad '.self::SECRET]], 401)]);

        $caught = $this->catchCloudException(fn () => $this->driver->validateCredentials($this->provider->credentials));

        $this->assertSame('credentials', $caught->reason);
        $this->assertStringNotContainsString(self::SECRET, $caught->getMessage());
    }

    public function test_create_posts_the_server_to_the_region_endpoint(): void
    {
        $this->fakeKeystone();
        Http::fake([self::NOVA.'/servers' => Http::response(['server' => ['id' => self::ID]], 202)]);

        $server = $this->driver->create($this->provider, $this->spec(options: [
            'network_ids' => ['net-1'], 'security_groups' => ['default'], 'key_name' => 'deploy',
            'availability_zone' => 'nova', 'unrelated' => 'dropped',
        ]));

        $request = Http::recorded()->last()[0];
        $this->assertTrue($request->hasHeader('X-Auth-Token', self::TOKEN));
        $this->assertTrue($request->hasHeader('OpenStack-API-Version', 'compute 2.37'));
        $this->assertSame([
            'server' => [
                'name' => 'jogger-host-1',
                'flavorRef' => 'flavor-1',
                'imageRef' => 'image-1',
                'user_data' => base64_encode("#cloud-config\nruncmd: []"),
                'metadata' => ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'],
                'networks' => [['uuid' => 'net-1']],
                'security_groups' => [['name' => 'default']],
                'key_name' => 'deploy',
                'availability_zone' => 'nova',
            ],
        ], $request->data());

        $this->assertSame('GRA11/'.self::ID, $server->id);
        $this->assertSame('starting', $server->status);
    }

    public function test_without_networks_nova_is_asked_to_pick_one(): void
    {
        $this->fakeKeystone();
        Http::fake([self::NOVA.'/servers' => Http::response(['server' => ['id' => self::ID]], 202)]);

        $this->driver->create($this->provider, $this->spec());

        $this->assertSame('auto', Http::recorded()->last()[0]->data()['server']['networks']);
    }

    public function test_a_region_without_a_compute_endpoint_is_an_error(): void
    {
        $this->fakeKeystone();

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec(location: 'SBG5')));

        $this->assertSame('api', $caught->reason);
        $this->assertStringContainsString('SBG5', $caught->getMessage());
    }

    public static function failures(): array
    {
        return [
            'quota (403)' => [403, ['forbidden' => ['message' => 'Quota exceeded for cores: Requested 8, but already used 32 of 32', 'code' => 403]], 'quota'],
            'over limit (413)' => [413, ['overLimit' => ['message' => 'Too many instances', 'code' => 413]], 'quota'],
            'other 403' => [403, ['forbidden' => ['message' => 'Policy does not allow this', 'code' => 403]], 'api'],
            'unauthorized' => [401, ['error' => ['message' => 'expired']], 'credentials'],
            'bad flavor' => [400, ['badRequest' => ['message' => 'Invalid flavorRef', 'code' => 400]], 'api'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('failures')]
    public function test_create_maps_errors(int $status, array $body, string $reason): void
    {
        $this->fakeKeystone();
        Http::fake([self::NOVA.'/servers' => Http::response($body, $status)]);

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertSame($reason, $caught->reason);
    }

    public static function statuses(): array
    {
        return [['BUILD', 'starting'], ['ACTIVE', 'running'], ['SHUTOFF', 'stopping'], ['ERROR', 'unknown']];
    }

    #[DataProvider('statuses')]
    public function test_get_maps_status_addresses_and_metadata(string $status, string $expected): void
    {
        $this->fakeKeystone();
        Http::fake([self::NOVA.'/servers/'.self::ID => Http::response(['server' => $this->serverJson(['status' => $status])])]);

        $server = $this->driver->get($this->provider, 'GRA11/'.self::ID);

        $this->assertSame($expected, $server?->status);
        $this->assertSame('203.0.113.7', $server?->ipv4);
        $this->assertSame('2001:db8::7', $server?->ipv6);
        $this->assertSame(['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'], $server?->labels);
    }

    public function test_a_missing_or_deleted_server_is_gone(): void
    {
        $this->fakeKeystone();
        Http::fake([
            self::NOVA.'/servers/missing' => Http::response(['itemNotFound' => ['message' => 'x']], 404),
            self::NOVA.'/servers/'.self::ID => Http::response(['server' => $this->serverJson(['status' => 'DELETED'])]),
        ]);

        $this->assertNull($this->driver->get($this->provider, 'GRA11/missing'));
        $this->assertNull($this->driver->get($this->provider, 'GRA11/'.self::ID));
    }

    public function test_delete_treats_404_as_success(): void
    {
        $this->fakeKeystone();
        Http::fake([self::NOVA.'/servers/'.self::ID => Http::response('', 404)]);

        $this->driver->delete($this->provider, 'GRA11/'.self::ID);

        $this->assertSame('DELETE', Http::recorded()->last()[0]->method());
    }

    public function test_server_ids_without_a_region_are_rejected(): void
    {
        $caught = $this->catchCloudException(fn () => $this->driver->delete($this->provider, self::ID));

        $this->assertSame('api', $caught->reason);
    }

    public function test_list_managed_keeps_only_this_instances_servers_and_follows_markers(): void
    {
        $provider = CloudProvider::factory()->create(['driver' => 'openstack', 'credentials' => $this->provider->credentials]);
        HostTemplate::factory()->create(['cloud_provider_id' => $provider->id, 'location' => 'GRA11']);

        $this->fakeKeystone();
        $first = array_map(fn (int $n) => $this->serverJson(['id' => "s{$n}"]), range(1, 99));
        $first[] = $this->serverJson(['id' => 's100', 'metadata' => ['managed-by' => 'jogger', 'jogger-instance' => 'other']]);

        Http::fake(function (Request $request) use ($first) {
            if (! str_starts_with($request->url(), self::NOVA.'/servers/detail')) {
                return null;
            }

            return str_contains($request->url(), 'marker=s100')
                ? Http::response(['servers' => [$this->serverJson(['id' => 's101']), $this->serverJson(['id' => 's102', 'metadata' => []])]])
                : Http::response(['servers' => $first]);
        });

        $servers = $this->driver->listManaged($provider, 'inst-1');

        $this->assertCount(100, $servers);
        $this->assertSame('GRA11/s101', end($servers)->id);
        $this->assertNotContains('GRA11/s100', array_map(fn ($s) => $s->id, $servers));
    }

    private function fakeKeystone(): void
    {
        Http::fake([self::AUTH.'/auth/tokens' => Http::response(['token' => ['catalog' => [
            ['type' => 'image', 'endpoints' => []],
            ['type' => 'compute', 'endpoints' => [
                ['interface' => 'internal', 'region_id' => 'GRA11', 'url' => 'https://internal.example.net/v2.1'],
                ['interface' => 'public', 'region_id' => 'GRA11', 'url' => self::NOVA.'/'],
            ]],
        ]]], 201, ['X-Subject-Token' => self::TOKEN])]);
    }

    private function catchCloudException(callable $call): CloudException
    {
        try {
            $call();
        } catch (CloudException $e) {
            return $e;
        }

        $this->fail('Expected a CloudException.');
    }

    /** @param array<string, mixed> $options */
    private function spec(array $options = [], string $location = 'GRA11'): ServerSpec
    {
        return new ServerSpec(
            name: 'jogger-host-1',
            serverType: 'flavor-1',
            location: $location,
            image: 'image-1',
            userData: "#cloud-config\nruncmd: []",
            ipv4: true,
            labels: ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'],
            options: $options,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function serverJson(array $overrides = []): array
    {
        return array_replace([
            'id' => self::ID,
            'name' => 'jogger-host-1',
            'status' => 'BUILD',
            'created' => '2026-11-10T08:00:00Z',
            'metadata' => ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'],
            'addresses' => ['Ext-Net' => [
                ['version' => 6, 'addr' => '2001:db8::7'],
                ['version' => 4, 'addr' => '203.0.113.7'],
            ]],
        ], $overrides);
    }
}
