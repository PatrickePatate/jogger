<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Data\CloudServer;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Drivers\HetznerDriver;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HetznerDriverTest extends TestCase
{
    private const TOKEN = 'hcloud-test-token-s3cr3t';

    private const API = 'https://api.hetzner.cloud/v1';

    private HetznerDriver $driver;

    private CloudProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->driver = new HetznerDriver;
        $this->provider = new CloudProvider([
            'name' => 'hetzner-test',
            'driver' => 'hetzner',
            'credentials' => ['token' => self::TOKEN],
        ]);
    }

    public function test_hetzner_is_registered_as_a_driver(): void
    {
        $this->assertSame(HetznerDriver::class, config('jogger.cloud.drivers.hetzner'));
        $this->assertInstanceOf(HetznerDriver::class, app(CloudManager::class)->driver('hetzner'));
    }

    public function test_create_sends_the_expected_request(): void
    {
        Http::fake([self::API.'/servers' => Http::response(['server' => $this->serverJson()], 201)]);

        $this->driver->create($this->provider, $this->spec());

        $request = $this->lastRequest();

        $this->assertSame('POST', $request->method());
        $this->assertSame(self::API.'/servers', $request->url());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertSame([
            'name' => 'jogger-host-1',
            'server_type' => 'cx22',
            'location' => 'fsn1',
            'image' => 123456,
            'user_data' => "#cloud-config\nruncmd: []",
            'labels' => [
                'managed-by' => 'jogger',
                'jogger-instance' => 'inst-1',
                'template' => 'my-template-x',
            ],
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
            'start_after_create' => true,
        ], $request->data());
    }

    public function test_image_names_are_sent_as_names(): void
    {
        Http::fake([self::API.'/servers' => Http::response(['server' => $this->serverJson()], 201)]);

        $this->driver->create($this->provider, $this->spec(image: 'ubuntu-24.04'));

        $this->assertSame('ubuntu-24.04', $this->lastRequest()->data()['image']);
    }

    public function test_ipv4_flag_is_sent_as_given(): void
    {
        Http::fake([self::API.'/servers' => Http::response(['server' => $this->serverJson()], 201)]);

        $this->driver->create($this->provider, $this->spec(ipv4: false));

        $this->assertSame(
            ['enable_ipv4' => false, 'enable_ipv6' => true],
            $this->lastRequest()->data()['public_net'],
        );
    }

    public function test_create_passes_only_the_known_options(): void
    {
        Http::fake([self::API.'/servers' => Http::response(['server' => $this->serverJson()], 201)]);

        $this->driver->create($this->provider, $this->spec(options: [
            'ssh_keys' => ['deploy-key'],
            'firewalls' => [['firewall' => 7]],
            'networks' => [9],
            'unrelated' => 'dropped',
        ]));

        $body = $this->lastRequest()->data();

        $this->assertSame(['deploy-key'], $body['ssh_keys']);
        $this->assertSame([['firewall' => 7]], $body['firewalls']);
        $this->assertSame([9], $body['networks']);
        $this->assertArrayNotHasKey('unrelated', $body);
    }

    public function test_label_values_are_sanitised_to_hetzner_rules(): void
    {
        Http::fake([self::API.'/servers' => Http::response(['server' => $this->serverJson()], 201)]);

        $this->driver->create($this->provider, $this->spec(labels: [
            'managed-by' => 'jogger',
            'template' => 'pool/x:1',
            'long' => str_repeat('a', 80),
        ]));

        $labels = $this->lastRequest()->data()['labels'];

        $this->assertSame('jogger', $labels['managed-by']);
        $this->assertSame('pool-x-1', $labels['template']);
        $this->assertSame(str_repeat('a', 63), $labels['long']);
    }

    public function test_create_maps_the_response_to_a_server(): void
    {
        Http::fake([self::API.'/servers' => Http::response(['server' => $this->serverJson()], 201)]);

        $server = $this->driver->create($this->provider, $this->spec());

        $this->assertInstanceOf(CloudServer::class, $server);
        $this->assertSame('42', $server->id);
        $this->assertSame('jogger-host-1', $server->name);
        $this->assertSame('starting', $server->status);
        $this->assertSame('203.0.113.7', $server->ipv4);
        $this->assertSame('2001:db8::/64', $server->ipv6);
        $this->assertEquals(CarbonImmutable::parse('2026-11-10T08:00:00+00:00'), $server->createdAt);
        $this->assertSame(['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'], $server->labels);
    }

    public function test_missing_public_addresses_map_to_null(): void
    {
        Http::fake([self::API.'/servers/42' => Http::response(['server' => $this->serverJson([
            'public_net' => ['ipv4' => ['ip' => null], 'ipv6' => null],
        ])])]);

        $server = $this->driver->get($this->provider, '42');

        $this->assertNull($server?->ipv4);
        $this->assertNull($server?->ipv6);
    }

    public static function statuses(): array
    {
        return [
            'initializing' => ['initializing', 'starting'],
            'starting' => ['starting', 'starting'],
            'running' => ['running', 'running'],
            'stopping' => ['stopping', 'stopping'],
            'off' => ['off', 'stopping'],
            'deleting' => ['deleting', 'stopping'],
            'migrating' => ['migrating', 'unknown'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_maps_hetzner_status_to_jogger_status(string $hetznerStatus, string $expected): void
    {
        Http::fake([self::API.'/servers/42' => Http::response(['server' => $this->serverJson(['status' => $hetznerStatus])])]);

        $server = $this->driver->get($this->provider, '42');

        $this->assertSame($expected, $server?->status);
    }

    public static function createFailures(): array
    {
        return [
            'resource_limit_exceeded is quota' => [412, 'resource_limit_exceeded', 'quota'],
            'limit_reached is quota' => [409, 'limit_reached', 'quota'],
            'resource_unavailable is quota' => [422, 'resource_unavailable', 'quota'],
            '401 is credentials' => [401, 'unauthorized', 'credentials'],
            '403 is credentials' => [403, 'forbidden', 'credentials'],
            'invalid input is api' => [422, 'invalid_input', 'api'],
            'server error is api' => [500, 'server_error', 'api'],
        ];
    }

    #[DataProvider('createFailures')]
    public function test_create_maps_errors(int $status, string $code, string $reason): void
    {
        Http::fake([self::API.'/servers' => Http::response(['error' => ['code' => $code, 'message' => 'nope']], $status)]);

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertSame($reason, $caught->reason);

        if ($reason === 'api') {
            $this->assertSame($status, $caught->getCode());
            $this->assertSame('nope', $caught->getMessage());
        }
    }

    public function test_get_returns_null_for_a_missing_server(): void
    {
        Http::fake([self::API.'/servers/42' => Http::response(['error' => ['code' => 'not_found', 'message' => 'gone']], 404)]);

        $this->assertNull($this->driver->get($this->provider, '42'));
    }

    public function test_get_returns_the_server(): void
    {
        Http::fake([self::API.'/servers/42' => Http::response(['server' => $this->serverJson()])]);

        $server = $this->driver->get($this->provider, '42');

        $this->assertSame('42', $server?->id);
        $this->assertSame('starting', $server?->status);
        $this->assertSame(self::API.'/servers/42', $this->lastRequest()->url());
    }

    public function test_delete_treats_404_as_success(): void
    {
        Http::fake([self::API.'/servers/42' => Http::response(['error' => ['code' => 'not_found', 'message' => 'gone']], 404)]);

        $this->driver->delete($this->provider, '42');

        $this->assertSame('DELETE', $this->lastRequest()->method());
        $this->assertSame(self::API.'/servers/42', $this->lastRequest()->url());
    }

    public function test_delete_sends_the_delete_request(): void
    {
        Http::fake([self::API.'/servers/42' => Http::response(['action' => ['id' => 1]])]);

        $this->driver->delete($this->provider, '42');

        $this->assertSame('DELETE', $this->lastRequest()->method());
    }

    public function test_delete_reports_other_failures(): void
    {
        Http::fake([self::API.'/servers/42' => Http::response(['error' => ['code' => 'locked', 'message' => 'locked']], 423)]);

        $caught = $this->catchCloudException(fn () => $this->driver->delete($this->provider, '42'));

        $this->assertSame('api', $caught->reason);
        $this->assertSame(423, $caught->getCode());
    }

    public function test_list_managed_follows_pagination_with_the_label_selector(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ($query['page'] ?? null) {
                '1' => Http::response([
                    'servers' => [$this->serverJson(['id' => 1])],
                    'meta' => ['pagination' => ['page' => 1, 'next_page' => 2]],
                ]),
                '2' => Http::response([
                    'servers' => [$this->serverJson(['id' => 2]), $this->serverJson(['id' => 3])],
                    'meta' => ['pagination' => ['page' => 2, 'next_page' => null]],
                ]),
                default => Http::response(['error' => ['code' => 'bad', 'message' => 'bad']], 400),
            };
        });

        $servers = $this->driver->listManaged($this->provider, 'inst-1');

        $this->assertSame(['1', '2', '3'], array_map(fn (CloudServer $s) => $s->id, $servers));

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $this->assertCount(2, $requests);

        foreach ($requests as $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame('/v1/servers', parse_url($request->url(), PHP_URL_PATH));
            $this->assertSame('managed-by=jogger,jogger-instance=inst-1', $query['label_selector']);
            $this->assertSame('50', $query['per_page']);
        }
    }

    public function test_validate_credentials_accepts_a_valid_token(): void
    {
        Http::fake([self::API.'/locations*' => Http::response(['locations' => []])]);

        $this->driver->validateCredentials(['token' => self::TOKEN]);

        $request = $this->lastRequest();

        $this->assertSame('GET', $request->method());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertStringStartsWith(self::API.'/locations?', $request->url());
        $this->assertStringContainsString('per_page=1', $request->url());
    }

    public static function rejectedStatuses(): array
    {
        return [
            '401' => [401],
            '403' => [403],
        ];
    }

    #[DataProvider('rejectedStatuses')]
    public function test_validate_credentials_rejects_a_bad_token(int $status): void
    {
        Http::fake([self::API.'/locations*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], $status)]);

        $caught = $this->catchCloudException(fn () => $this->driver->validateCredentials(['token' => self::TOKEN]));

        $this->assertSame('credentials', $caught->reason);
        $this->assertStringNotContainsString(self::TOKEN, $caught->getMessage());
    }

    public function test_token_never_appears_in_exception_messages(): void
    {
        Http::fake([self::API.'/servers' => Http::response([
            'error' => ['code' => 'invalid_input', 'message' => 'token '.self::TOKEN.' is malformed'],
        ], 422)]);

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertStringNotContainsString(self::TOKEN, $caught->getMessage());
        $this->assertStringContainsString('[redacted]', $caught->getMessage());
    }

    public function test_connection_errors_are_reported_as_cloud_exceptions(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for '.self::API));

        $caught = $this->catchCloudException(fn () => $this->driver->get($this->provider, '42'));

        $this->assertSame('api', $caught->reason);
        $this->assertStringNotContainsString(self::TOKEN, $caught->getMessage());
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

    private function lastRequest(): Request
    {
        return Http::recorded()->last()[0];
    }

    private function spec(
        string $image = '123456',
        bool $ipv4 = true,
        array $labels = ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1', 'template' => 'my template x'],
        array $options = [],
    ): ServerSpec {
        return new ServerSpec(
            name: 'jogger-host-1',
            serverType: 'cx22',
            location: 'fsn1',
            image: $image,
            userData: "#cloud-config\nruncmd: []",
            ipv4: $ipv4,
            labels: $labels,
            options: $options,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function serverJson(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 42,
            'name' => 'jogger-host-1',
            'status' => 'initializing',
            'created' => '2026-11-10T08:00:00+00:00',
            'labels' => ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'],
            'public_net' => [
                'ipv4' => ['ip' => '203.0.113.7'],
                'ipv6' => ['ip' => '2001:db8::/64'],
            ],
        ], $overrides);
    }
}
