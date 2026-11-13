<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Drivers\AwsEc2Driver;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AwsEc2DriverTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYSECRETKEY';

    private const KEY_ID = 'AKIAEXAMPLE12345678';

    private const URL = 'https://ec2.eu-west-3.amazonaws.com/';

    private AwsEc2Driver $driver;

    private CloudProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->driver = new AwsEc2Driver;
        $this->provider = new CloudProvider([
            'name' => 'aws', 'driver' => 'aws',
            'credentials' => ['access_key_id' => self::KEY_ID, 'token' => self::SECRET],
        ]);
    }

    public function test_is_registered_as_a_driver(): void
    {
        $this->assertInstanceOf(AwsEc2Driver::class, app(CloudManager::class)->driver('aws'));
    }

    public function test_requests_are_signed_with_signature_v4(): void
    {
        Http::fake([self::URL => Http::response($this->runResponse())]);

        $this->driver->create($this->provider, $this->spec());

        $request = $this->last();
        $this->assertSame('POST', $request->method());
        $auth = $request->header('Authorization')[0];
        $this->assertMatchesRegularExpression(
            '#^AWS4-HMAC-SHA256 Credential='.self::KEY_ID.'/\d{8}/eu-west-3/ec2/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature=[0-9a-f]{64}$#',
            $auth,
        );
        $this->assertStringNotContainsString(self::SECRET, $auth);
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $request->header('X-Amz-Date')[0]);
    }

    public function test_create_sends_run_instances_and_maps_the_instance(): void
    {
        Http::fake([self::URL => Http::response($this->runResponse())]);

        $server = $this->driver->create($this->provider, $this->spec(options: [
            'subnet_id' => 'subnet-1', 'security_group_ids' => ['sg-1'], 'key_name' => 'deploy',
            'spot' => true, 'root_volume_gb' => 80, 'unrelated' => 'dropped',
        ]));

        parse_str($this->last()->body(), $p);

        $this->assertSame('RunInstances', $p['Action']);
        $this->assertSame('ami-0abc', $p['ImageId']);
        $this->assertSame('c6i.xlarge', $p['InstanceType']);
        $this->assertSame(base64_encode("#cloud-config\nruncmd: []"), $p['UserData']);
        $this->assertSame('subnet-1', $p['NetworkInterface_1_SubnetId'] ?? $p['NetworkInterface.1.SubnetId']);
        $this->assertSame('true', $p['NetworkInterface.1.AssociatePublicIpAddress'] ?? $p['NetworkInterface_1_AssociatePublicIpAddress']);
        $this->assertSame('spot', $p['InstanceMarketOptions.MarketType'] ?? $p['InstanceMarketOptions_MarketType']);
        $this->assertSame('deploy', $p['KeyName']);
        $this->assertStringNotContainsString('unrelated', $this->last()->body());

        $this->assertSame('eu-west-3/i-0abc123', $server->id);
        $this->assertSame('jogger-host-1', $server->name);
        $this->assertSame('starting', $server->status);
        $this->assertSame('203.0.113.7', $server->ipv4);
        $this->assertSame(['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'], $server->labels);
    }

    public function test_without_a_subnet_security_groups_are_top_level(): void
    {
        Http::fake([self::URL => Http::response($this->runResponse())]);

        $this->driver->create($this->provider, $this->spec(options: ['security_group_ids' => ['sg-9']]));

        $this->assertStringContainsString('SecurityGroupId.1=sg-9', urldecode($this->last()->body()));
        $this->assertStringNotContainsString('NetworkInterface', $this->last()->body());
    }

    public static function errors(): array
    {
        return [
            'instance limit' => ['InstanceLimitExceeded', 'quota'],
            'no capacity' => ['InsufficientInstanceCapacity', 'quota'],
            'vcpu limit' => ['VcpuLimitExceeded', 'quota'],
            'auth failure' => ['AuthFailure', 'credentials'],
            'bad signature' => ['SignatureDoesNotMatch', 'credentials'],
            'invalid ami' => ['InvalidAMIID.NotFound', 'api'],
        ];
    }

    #[DataProvider('errors')]
    public function test_create_maps_errors(string $code, string $reason): void
    {
        Http::fake([self::URL => Http::response($this->errorXml($code, 'nope '.self::SECRET), 400)]);

        $caught = $this->catchCloudException(fn () => $this->driver->create($this->provider, $this->spec()));

        $this->assertSame($reason, $caught->reason);
        $this->assertStringNotContainsString(self::SECRET, $caught->getMessage());
    }

    public static function states(): array
    {
        return [['pending', 'starting'], ['running', 'running'], ['stopping', 'stopping'], ['stopped', 'stopping'], ['shutting-down', 'stopping'], ['weird', 'unknown']];
    }

    #[DataProvider('states')]
    public function test_maps_state(string $state, string $expected): void
    {
        Http::fake([self::URL => Http::response($this->describeResponse([['id' => 'i-1', 'state' => $state]]))]);

        $this->assertSame($expected, $this->driver->get($this->provider, 'eu-west-3/i-1')?->status);
    }

    public function test_a_terminated_or_unknown_instance_is_gone(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push($this->describeResponse([['id' => 'i-1', 'state' => 'terminated']]))
            ->push($this->errorXml('InvalidInstanceID.NotFound', "The instance ID 'i-2' does not exist"), 400)]);

        $this->assertNull($this->driver->get($this->provider, 'eu-west-3/i-1'));
        $this->assertNull($this->driver->get($this->provider, 'eu-west-3/i-2'));
    }

    public function test_delete_terminates_and_ignores_missing_instances(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('<TerminateInstancesResponse xmlns="http://ec2.amazonaws.com/doc/2016-11-15/"><instancesSet/></TerminateInstancesResponse>')
            ->push($this->errorXml('InvalidInstanceID.NotFound', "The instance ID 'i-1' does not exist"), 400)]);

        $this->driver->delete($this->provider, 'eu-west-3/i-1');
        parse_str($this->last()->body(), $p);
        $this->assertSame('TerminateInstances', $p['Action']);
        $this->assertSame('i-1', $p['InstanceId.1'] ?? $p['InstanceId_1']);

        $this->driver->delete($this->provider, 'eu-west-3/i-1');
    }

    public function test_server_ids_without_a_region_are_rejected(): void
    {
        $caught = $this->catchCloudException(fn () => $this->driver->delete($this->provider, 'i-1'));

        $this->assertSame('api', $caught->reason);
    }

    public function test_list_managed_filters_by_tags_for_each_template_region_and_follows_tokens(): void
    {
        $provider = CloudProvider::factory()->create(['driver' => 'aws', 'credentials' => ['access_key_id' => self::KEY_ID, 'token' => self::SECRET]]);
        HostTemplate::factory()->create(['cloud_provider_id' => $provider->id, 'location' => 'eu-west-3']);

        Http::fake(function (Request $request) {
            return str_contains($request->body(), 'NextToken')
                ? Http::response($this->describeResponse([['id' => 'i-2', 'state' => 'running']]))
                : Http::response($this->describeResponse([['id' => 'i-1', 'state' => 'running']], 'tok'));
        });

        $servers = $this->driver->listManaged($provider, 'inst-1');

        $this->assertSame(['eu-west-3/i-1', 'eu-west-3/i-2'], array_map(fn ($s) => $s->id, $servers));
        $body = urldecode(Http::recorded()->first()[0]->body());
        $this->assertStringContainsString('tag:managed-by', $body);
        $this->assertStringContainsString('tag:jogger-instance', $body);
    }

    public function test_validate_credentials(): void
    {
        Http::fake(['https://ec2.us-east-1.amazonaws.com/' => Http::response($this->describeResponse([]))]);

        $this->driver->validateCredentials(['access_key_id' => self::KEY_ID, 'token' => self::SECRET]);

        $this->assertSame('DescribeInstances', $this->params($this->last())['Action']);
    }

    public function test_validate_credentials_rejects_a_bad_key(): void
    {
        Http::fake(['https://ec2.us-east-1.amazonaws.com/' => Http::response($this->errorXml('AuthFailure', 'bad'), 401)]);

        $caught = $this->catchCloudException(fn () => $this->driver->validateCredentials(['access_key_id' => self::KEY_ID, 'token' => self::SECRET]));

        $this->assertSame('credentials', $caught->reason);
    }

    /** @return array<string, string> */
    private function params(Request $request): array
    {
        parse_str($request->body(), $p);

        return $p;
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

    /** @param array<string, mixed> $options */
    private function spec(array $options = []): ServerSpec
    {
        return new ServerSpec(
            name: 'jogger-host-1',
            serverType: 'c6i.xlarge',
            location: 'EU-WEST-3',
            image: 'ami-0abc',
            userData: "#cloud-config\nruncmd: []",
            ipv4: true,
            labels: ['managed-by' => 'jogger', 'jogger-instance' => 'inst-1'],
            options: $options,
        );
    }

    private function instanceXml(string $id, string $state): string
    {
        return "<item><instanceId>{$id}</instanceId><instanceState><code>0</code><name>{$state}</name></instanceState>"
            .'<launchTime>2026-11-10T08:00:00.000Z</launchTime><ipAddress>203.0.113.7</ipAddress>'
            .'<tagSet><item><key>Name</key><value>jogger-host-1</value></item><item><key>managed-by</key><value>jogger</value></item>'
            .'<item><key>jogger-instance</key><value>inst-1</value></item></tagSet></item>';
    }

    private function runResponse(): string
    {
        return '<RunInstancesResponse xmlns="http://ec2.amazonaws.com/doc/2016-11-15/"><instancesSet>'
            .$this->instanceXml('i-0abc123', 'pending').'</instancesSet></RunInstancesResponse>';
    }

    /** @param list<array{id: string, state: string}> $instances */
    private function describeResponse(array $instances, ?string $nextToken = null): string
    {
        $items = implode('', array_map(fn (array $i) => $this->instanceXml($i['id'], $i['state']), $instances));

        return '<DescribeInstancesResponse xmlns="http://ec2.amazonaws.com/doc/2016-11-15/"><reservationSet><item><instancesSet>'
            .$items.'</instancesSet></item></reservationSet>'.($nextToken !== null ? "<nextToken>{$nextToken}</nextToken>" : '').'</DescribeInstancesResponse>';
    }

    private function errorXml(string $code, string $message): string
    {
        return "<Response><Errors><Error><Code>{$code}</Code><Message>{$message}</Message></Error></Errors><RequestID>x</RequestID></Response>";
    }
}
