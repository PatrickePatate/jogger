<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Drivers\DigitalOceanDriver;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DigitalOceanDriverTest extends TestCase
{
    private const TOKEN = 'dop_v1_s3cr3t';

    private const API = 'https://api.digitalocean.com/v2';

    private DigitalOceanDriver $driver;

    private CloudProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->driver = new DigitalOceanDriver;
        $this->provider = new CloudProvider(['name' => 'do', 'driver' => 'digitalocean', 'credentials' => ['token' => self::TOKEN]]);
    }

    public function test_is_registered_as_a_driver(): void
    {
        $this->assertInstanceOf(DigitalOceanDriver::class, app(CloudManager::class)->driver('digitalocean'));
    }

    public function test_create_sends_the_expected_request_and_maps_the_droplet(): void
    {
        Http::fake([self::API.'/droplets' => Http::response(['droplet' => $this->dropletJson()], 202)]);

        $server = $this->driver->create($this->provider, $this->spec());

        $request = $this->last();
        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertSame([
            'name' => 'jogger-host-1',
            'region' => 'fra1',
            'size' => 's-2vcpu-4gb',
            'image' => 'ubuntu-24-04-x64',
            'user_data' => "#cloud-config\nruncmd: []",
            'tags' => ['managed-by:jogger', 'jogger-instance:inst-1', 'template:pool-x'],
            'ipv6' => true,
            'ssh_keys' => [123],
        ], $request->data());

        $this->assertSame('42', $server->id);
        $this->assertSame('starting', $server->status);
        $this->assertSame('203.0.113.7', $server->ipv4);
        $this->assertSame('2001:db8::1', $server->ipv6);
        $this->assertSame(['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'], $server->labels);
    }

    public function test_snapshot_ids_are_sent_as_numbers(): void
    {
        Http::fake([self::API.'/droplets' => Http::response(['droplet' => $this->dropletJson()], 202)]);

        $this->driver->create($this->provider, $this->spec(image: '987654'));

        $this->assertSame(987654, $this->last()->data()['image']);
    }

    public static function statuses(): array
    {
        return [['new', 'starting'], ['active', 'running'], ['off', 'stopping'], ['archive', 'stopping'], ['other', 'unknown']];
    }

    #[DataProvider('statuses')]
    public function test_maps_status(string $status, string $expected): void
    {
        Http::fake([self::API.'/droplets/42' => Http::response(['droplet' => $this->dropletJson(['status' => $status])])]);

        $this->assertSame($expected, $this->driver->get($this->provider, '42')?->status);
    }

    public static function failures(): array
    {
        return [
            '401' => [401, 'Unable to authenticate you', 'credentials'],
            '403' => [403, 'forbidden', 'credentials'],
            'droplet limit' => [422, 'creating this droplet will exceed your droplet limit', 'quota'],
            'invalid size' => [422, 'You specified an invalid size', 'api'],
            'server error' => [500, 'boom', 'api'],
        ];
    }

    #[DataProvider('failures')]
    public function test_create_maps_errors(int $status, string $message, string $reason): void
    {
        Http::fake([self::API.'/droplets' => Http::response(['id' => 'x', 'message' => $message], $status)]);

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertSame($reason, $caught->reason);
    }

    public function test_token_is_scrubbed_from_messages(): void
    {
        Http::fake([self::API.'/droplets' => Http::response(['message' => 'bad '.self::TOKEN], 422)]);

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertStringNotContainsString(self::TOKEN, $caught->getMessage());
    }

    public function test_get_and_delete_treat_404_as_gone(): void
    {
        Http::fake([self::API.'/droplets/42' => Http::response(['message' => 'not found'], 404)]);

        $this->assertNull($this->driver->get($this->provider, '42'));
        $this->driver->delete($this->provider, '42');

        $this->assertSame('DELETE', $this->last()->method());
    }

    public function test_list_managed_filters_by_both_tags_and_follows_pages(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['page'] === '1'
                ? Http::response(['droplets' => [$this->dropletJson(['id' => 1]), $this->dropletJson(['id' => 2, 'tags' => ['jogger-instance:inst-1']])], 'links' => ['pages' => ['next' => 'x']]])
                : Http::response(['droplets' => [$this->dropletJson(['id' => 3])], 'links' => []]);
        });

        $servers = $this->driver->listManaged($this->provider, 'inst-1');

        $this->assertSame(['1', '3'], array_map(fn ($s) => $s->id, $servers));
        $this->assertSame('jogger-instance:inst-1', $this->queryOf(Http::recorded()->first()[0])['tag_name']);
    }

    public function test_validate_credentials(): void
    {
        Http::fake([self::API.'/account' => Http::response(['account' => []])]);
        $this->driver->validateCredentials(['token' => self::TOKEN]);

        $this->assertSame(self::API.'/account', $this->last()->url());
    }

    public function test_validate_credentials_rejects_a_bad_token(): void
    {
        Http::fake([self::API.'/account' => Http::response(['message' => 'nope'], 401)]);
        $caught = $this->catchCloudException(fn () => $this->driver->validateCredentials(['token' => self::TOKEN]));

        $this->assertSame('credentials', $caught->reason);
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

    private function last(): Request
    {
        return Http::recorded()->last()[0];
    }

    /** @return array<string, mixed> */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function spec(string $image = 'ubuntu-24-04-x64'): ServerSpec
    {
        return new ServerSpec(
            name: 'jogger-host-1',
            serverType: 'S-2VCPU-4GB',
            location: 'FRA1',
            image: $image,
            userData: "#cloud-config\nruncmd: []",
            ipv4: true,
            labels: ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1', 'template' => 'pool x'],
            options: ['ssh_keys' => [123], 'unrelated' => 'dropped'],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function dropletJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 42,
            'name' => 'jogger-host-1',
            'status' => 'new',
            'created_at' => '2026-11-10T08:00:00Z',
            'tags' => ['managed-by:jogger', 'jogger-instance:inst-1'],
            'networks' => [
                'v4' => [['type' => 'private', 'ip_address' => '10.0.0.2'], ['type' => 'public', 'ip_address' => '203.0.113.7']],
                'v6' => [['type' => 'public', 'ip_address' => '2001:db8::1']],
            ],
        ], $overrides);
    }
}
