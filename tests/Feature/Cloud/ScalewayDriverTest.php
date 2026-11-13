<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Drivers\ScalewayDriver;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScalewayDriverTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'scw-secret-key';

    private const API = 'https://api.scaleway.com/instance/v1/zones';

    private const ID = '11111111-2222-3333-4444-555555555555';

    private ScalewayDriver $driver;

    private CloudProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->driver = new ScalewayDriver;
        $this->provider = new CloudProvider([
            'name' => 'scw', 'driver' => 'scaleway',
            'credentials' => ['token' => self::KEY, 'project_id' => 'proj-1'],
        ]);
    }

    public function test_is_registered_as_a_driver(): void
    {
        $this->assertInstanceOf(ScalewayDriver::class, app(CloudManager::class)->driver('scaleway'));
    }

    public function test_create_makes_the_server_then_sets_user_data_and_powers_on(): void
    {
        $this->fakeCreate();

        $server = $this->driver->create($this->provider, $this->spec());

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0])->values();
        $this->assertCount(3, $requests);

        $this->assertSame('POST', $requests[0]->method());
        $this->assertSame(self::API.'/fr-par-1/servers', $requests[0]->url());
        $this->assertTrue($requests[0]->hasHeader('X-Auth-Token', self::KEY));
        $this->assertSame([
            'name' => 'jogger-host-1',
            'project' => 'proj-1',
            'commercial_type' => 'DEV1-M',
            'image' => 'ubuntu_jammy',
            'tags' => ['managed-by:jogger', 'jogger-instance:inst-1'],
            'enable_ipv6' => true,
            'dynamic_ip_required' => true,
        ], $requests[0]->data());

        $this->assertSame('PUT', $requests[1]->method());
        $this->assertSame(self::API.'/fr-par-1/servers/'.self::ID.'/user_data/cloud-init', $requests[1]->url());
        $this->assertSame("#cloud-config\nruncmd: []", $requests[1]->body());

        $this->assertSame(['action' => 'poweron'], $requests[2]->data());

        $this->assertSame('fr-par-1/'.self::ID, $server->id);
        $this->assertSame(['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'], $server->labels);
    }

    public function test_a_failed_power_on_deletes_the_half_built_server(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/servers')) {
                return Http::response(['server' => $this->serverJson()], 201);
            }
            if ($request->method() === 'POST' && ($request->data()['action'] ?? null) === 'poweron') {
                return Http::response(['type' => 'invalid_request_error', 'message' => 'bad'], 400);
            }

            return Http::response([], 200);
        });

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertSame('api', $caught->reason);
        $this->assertSame(['action' => 'terminate'], Http::recorded()->last()[0]->data());
    }

    public static function failures(): array
    {
        return [
            'quota' => [403, 'quotas_exceeded', 'quota'],
            'unauthorized' => [401, 'denied_authentication', 'credentials'],
            'invalid' => [400, 'invalid_arguments', 'api'],
        ];
    }

    #[DataProvider('failures')]
    public function test_create_maps_errors(int $status, string $type, string $reason): void
    {
        Http::fake([self::API.'/fr-par-1/servers' => Http::response(['type' => $type, 'message' => 'nope '.self::KEY], $status)]);

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertSame($reason, $caught->reason);
        $this->assertStringNotContainsString(self::KEY, $caught->getMessage());
    }

    public static function states(): array
    {
        return [['starting', 'starting'], ['running', 'running'], ['stopping', 'stopping'], ['stopped', 'stopping'], ['locked', 'unknown']];
    }

    #[DataProvider('states')]
    public function test_maps_state(string $state, string $expected): void
    {
        Http::fake([self::API.'/fr-par-1/servers/'.self::ID => Http::response(['server' => $this->serverJson(['state' => $state])])]);

        $this->assertSame($expected, $this->driver->get($this->provider, 'fr-par-1/'.self::ID)?->status);
    }

    public function test_get_and_delete_treat_404_as_gone(): void
    {
        Http::fake([self::API.'/fr-par-1/servers/'.self::ID.'*' => Http::response(['type' => 'not_found'], 404)]);

        $this->assertNull($this->driver->get($this->provider, 'fr-par-1/'.self::ID));
        $this->driver->delete($this->provider, 'fr-par-1/'.self::ID);

        $this->assertSame(['action' => 'terminate'], Http::recorded()->last()[0]->data());
    }

    public function test_server_ids_without_a_zone_are_rejected(): void
    {
        $caught = $this->catchCloudException(fn () => $this->driver->get($this->provider, self::ID));

        $this->assertSame('api', $caught->reason);
    }

    public function test_list_managed_queries_each_template_zone(): void
    {
        $provider = CloudProvider::factory()->create(['driver' => 'scaleway', 'credentials' => ['token' => self::KEY, 'project_id' => 'proj-1']]);
        HostTemplate::factory()->create(['cloud_provider_id' => $provider->id, 'location' => 'fr-par-1']);
        HostTemplate::factory()->create(['cloud_provider_id' => $provider->id, 'location' => 'nl-ams-1']);

        Http::fake([
            self::API.'/fr-par-1/servers*' => Http::response(['servers' => [$this->serverJson()]]),
            self::API.'/nl-ams-1/servers*' => Http::response(['servers' => []]),
        ]);

        $servers = $this->driver->listManaged($provider, 'inst-1');

        $this->assertSame(['fr-par-1/'.self::ID], array_map(fn ($s) => $s->id, $servers));
        parse_str((string) parse_url(Http::recorded()->first()[0]->url(), PHP_URL_QUERY), $query);
        $this->assertSame('managed-by:jogger,jogger-instance:inst-1', $query['tags']);
    }

    public function test_validate_credentials(): void
    {
        Http::fake([self::API.'/fr-par-1/servers*' => Http::response(['servers' => []])]);
        $this->driver->validateCredentials(['token' => self::KEY, 'project_id' => 'proj-1']);

        $this->assertTrue(Http::recorded()->last()[0]->hasHeader('X-Auth-Token', self::KEY));
    }

    public function test_validate_credentials_rejects_a_bad_key(): void
    {
        Http::fake([self::API.'/fr-par-1/servers*' => Http::response(['type' => 'denied_authentication', 'message' => 'x'], 403)]);
        $caught = $this->catchCloudException(fn () => $this->driver->validateCredentials(['token' => self::KEY, 'project_id' => 'proj-1']));

        $this->assertSame('credentials', $caught->reason);
    }

    private function fakeCreate(): void
    {
        Http::fake([
            self::API.'/fr-par-1/servers' => Http::response(['server' => $this->serverJson()], 201),
            self::API.'/fr-par-1/servers/'.self::ID.'/user_data/cloud-init' => Http::response('', 204),
            self::API.'/fr-par-1/servers/'.self::ID.'/action' => Http::response(['task' => []], 202),
        ]);
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

    private function spec(): ServerSpec
    {
        return new ServerSpec(
            name: 'jogger-host-1',
            serverType: 'dev1-m',
            location: 'FR-PAR-1',
            image: 'ubuntu_jammy',
            userData: "#cloud-config\nruncmd: []",
            ipv4: true,
            labels: ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'],
            options: [],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function serverJson(array $overrides = []): array
    {
        return array_replace([
            'id' => self::ID,
            'name' => 'jogger-host-1',
            'state' => 'starting',
            'creation_date' => '2026-11-10T08:00:00+00:00',
            'tags' => ['managed-by:jogger', 'jogger-instance:inst-1'],
            'public_ip' => ['address' => '203.0.113.7'],
            'ipv6' => ['address' => '2001:db8::1'],
        ], $overrides);
    }
}
