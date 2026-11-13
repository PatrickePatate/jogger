<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Drivers;

use App\Domain\Cloud\Contracts\CloudDriver;
use App\Domain\Cloud\Data\CloudServer;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * AWS EC2 Query API, signed with Signature Version 4 (no SDK). The template's location is a region (`eu-west-3`),
 * the server type an instance type (`c6i.xlarge`), the image an AMI id. A server id is `{region}/{instance-id}`.
 * Credentials: an access key id (`access_key_id`) and its secret (`token`).
 *
 * Template options: `subnet_id`, `security_group_ids` (list), `key_name`, `iam_instance_profile` (name),
 * `spot` (bool, one-time spot request), `root_volume_gb`. Without `subnet_id` the region's default VPC is used.
 */
final class AwsEc2Driver implements CloudDriver
{
    private const API_VERSION = '2016-11-15';

    /** Region used to check credentials; the key is not region-scoped. */
    private const CHECK_REGION = 'us-east-1';

    private const QUOTA_CODES = [
        'InstanceLimitExceeded', 'VcpuLimitExceeded', 'InsufficientInstanceCapacity', 'InsufficientHostCapacity',
        'MaxSpotInstanceCountExceeded', 'SpotMaxPriceTooLow', 'InsufficientFreeAddressesInSubnet', 'Unsupported',
    ];

    private const CREDENTIAL_CODES = [
        'AuthFailure', 'UnauthorizedOperation', 'InvalidClientTokenId', 'SignatureDoesNotMatch',
        'MissingAuthenticationToken', 'ExpiredToken', 'AccessDenied',
    ];

    private const NOT_FOUND_CODES = ['InvalidInstanceID.NotFound', 'InvalidInstanceID.Malformed'];

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(array $credentials): void
    {
        $this->call($credentials, self::CHECK_REGION, 'DescribeInstances', ['MaxResults' => 5]);
    }

    public function create(CloudProvider $provider, ServerSpec $spec): CloudServer
    {
        $region = mb_strtolower($spec->location);
        $options = $spec->options;

        $params = [
            'ImageId' => $spec->image,
            'InstanceType' => $spec->serverType,
            'MinCount' => 1,
            'MaxCount' => 1,
            'UserData' => base64_encode($spec->userData),
            'InstanceInitiatedShutdownBehavior' => 'terminate',
            'TagSpecification.1.ResourceType' => 'instance',
            'TagSpecification.2.ResourceType' => 'volume',
        ];

        $tags = $this->tags($spec->labels) + ['Name' => $spec->name];
        $n = 1;
        foreach ($tags as $key => $value) {
            foreach ([1, 2] as $spec_index) {
                $params["TagSpecification.{$spec_index}.Tag.{$n}.Key"] = $key;
                $params["TagSpecification.{$spec_index}.Tag.{$n}.Value"] = $value;
            }
            $n++;
        }

        $groups = array_values(array_filter((array) ($options['security_group_ids'] ?? []), is_string(...)));
        $subnet = $options['subnet_id'] ?? null;

        if (is_string($subnet) && $subnet !== '') {
            // Public IPv4 is requested per network interface when the subnet is given.
            $params['NetworkInterface.1.DeviceIndex'] = 0;
            $params['NetworkInterface.1.SubnetId'] = $subnet;
            $params['NetworkInterface.1.AssociatePublicIpAddress'] = $spec->ipv4 ? 'true' : 'false';
            foreach ($groups as $i => $group) {
                $params['NetworkInterface.1.SecurityGroupId.'.($i + 1)] = $group;
            }
        } else {
            foreach ($groups as $i => $group) {
                $params['SecurityGroupId.'.($i + 1)] = $group;
            }
        }

        if (is_string($options['key_name'] ?? null) && $options['key_name'] !== '') {
            $params['KeyName'] = $options['key_name'];
        }
        if (is_string($options['iam_instance_profile'] ?? null) && $options['iam_instance_profile'] !== '') {
            $params['IamInstanceProfile.Name'] = $options['iam_instance_profile'];
        }
        if (isset($options['root_volume_gb']) && is_numeric($options['root_volume_gb'])) {
            $params['BlockDeviceMapping.1.DeviceName'] = '/dev/sda1';
            $params['BlockDeviceMapping.1.Ebs.VolumeSize'] = (int) $options['root_volume_gb'];
            $params['BlockDeviceMapping.1.Ebs.DeleteOnTermination'] = 'true';
        }
        if (($options['spot'] ?? false) === true) {
            $params['InstanceMarketOptions.MarketType'] = 'spot';
            $params['InstanceMarketOptions.SpotOptions.SpotInstanceType'] = 'one-time';
            $params['InstanceMarketOptions.SpotOptions.InstanceInterruptionBehavior'] = 'terminate';
        }

        $xml = $this->call($provider->credentials, $region, 'RunInstances', $params);

        $instance = $xml->instancesSet->item ?? null;
        if (! $instance instanceof SimpleXMLElement || (string) $instance->instanceId === '') {
            throw CloudException::api('AWS returned an unexpected response.', 200);
        }

        return $this->mapInstance($instance, $region);
    }

    public function get(CloudProvider $provider, string $serverId): ?CloudServer
    {
        [$region, $id] = $this->split($serverId);

        try {
            $xml = $this->call($provider->credentials, $region, 'DescribeInstances', ['InstanceId.1' => $id]);
        } catch (CloudException $e) {
            if ($e->getCode() === 400 && str_contains($e->getMessage(), 'does not exist')) {
                return null;
            }

            throw $e;
        }

        foreach ($xml->xpath('//reservationSet/item/instancesSet/item') ?: [] as $instance) {
            // A terminated instance stays listed for about an hour; for Jogger it is gone.
            return (string) $instance->instanceState->name === 'terminated' ? null : $this->mapInstance($instance, $region);
        }

        return null;
    }

    public function delete(CloudProvider $provider, string $serverId): void
    {
        [$region, $id] = $this->split($serverId);

        try {
            $this->call($provider->credentials, $region, 'TerminateInstances', ['InstanceId.1' => $id]);
        } catch (CloudException $e) {
            if ($e->getCode() === 400 && str_contains($e->getMessage(), 'does not exist')) {
                return;
            }

            throw $e;
        }
    }

    public function listManaged(CloudProvider $provider, string $instanceId): array
    {
        $servers = [];

        foreach ($this->regions($provider) as $region) {
            $token = null;

            do {
                $params = [
                    'Filter.1.Name' => 'tag:'.self::MANAGED_BY_LABEL,
                    'Filter.1.Value.1' => 'jogger',
                    'Filter.2.Name' => 'tag:'.self::INSTANCE_LABEL,
                    'Filter.2.Value.1' => $this->tagValue($instanceId),
                    'Filter.3.Name' => 'instance-state-name',
                    'Filter.3.Value.1' => 'pending',
                    'Filter.3.Value.2' => 'running',
                    'Filter.3.Value.3' => 'stopping',
                    'Filter.3.Value.4' => 'stopped',
                    'Filter.3.Value.5' => 'shutting-down',
                    'MaxResults' => 100,
                ];
                if ($token !== null) {
                    $params['NextToken'] = $token;
                }

                $xml = $this->call($provider->credentials, $region, 'DescribeInstances', $params);

                foreach ($xml->xpath('//reservationSet/item/instancesSet/item') ?: [] as $instance) {
                    $servers[] = $this->mapInstance($instance, $region);
                }

                $next = (string) ($xml->nextToken ?? '');
                $token = $next !== '' && $next !== $token ? $next : null;
            } while ($token !== null);
        }

        return $servers;
    }

    /** @return list<string> distinct regions used by this provider's templates */
    private function regions(CloudProvider $provider): array
    {
        return $provider->templates()->pluck('location')
            ->map(fn (mixed $region) => mb_strtolower((string) $region))
            ->unique()->values()->all();
    }

    /**
     * Sends one signed EC2 action and returns the parsed XML.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, scalar>  $params
     */
    private function call(array $credentials, string $region, string $action, array $params = []): SimpleXMLElement
    {
        $accessKey = is_string($credentials['access_key_id'] ?? null) ? $credentials['access_key_id'] : '';
        $secret = is_string($credentials['token'] ?? null) ? $credentials['token'] : '';

        $params = ['Action' => $action, 'Version' => self::API_VERSION] + $params;
        ksort($params);
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $host = "ec2.{$region}.amazonaws.com";
        $headers = $this->sign($accessKey, $secret, $region, $host, $body);

        try {
            $response = Http::withHeaders($headers)
                ->withBody($body, 'application/x-www-form-urlencoded; charset=utf-8')
                ->timeout(30)
                ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->post("https://{$host}/");
        } catch (ConnectionException) {
            throw CloudException::api('Could not reach AWS EC2.', 0);
        }

        if (! $response->successful()) {
            throw $this->failure($response, $secret, $accessKey);
        }

        return $this->parse($response->body());
    }

    /**
     * AWS Signature Version 4 for a form-encoded POST to `/`.
     *
     * @return array<string, string>
     */
    private function sign(string $accessKey, string $secret, string $region, string $host, string $body): array
    {
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $contentType = 'application/x-www-form-urlencoded; charset=utf-8';

        $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\nx-amz-date:{$now}\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $canonicalRequest = implode("\n", ['POST', '/', '', $canonicalHeaders, $signedHeaders, hash('sha256', $body)]);

        $scope = "{$date}/{$region}/ec2/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 'ec2', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secret, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return [
            'X-Amz-Date' => $now,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    private function failure(Response $response, string $secret, string $accessKey): CloudException
    {
        $status = $response->status();
        $code = '';
        $message = '';

        try {
            $xml = $this->parse($response->body());
            $code = (string) ($xml->Errors->Error->Code ?? $xml->Error->Code ?? '');
            $message = (string) ($xml->Errors->Error->Message ?? $xml->Error->Message ?? '');
        } catch (CloudException) {
            // Not XML: fall through to the generic message.
        }

        foreach (array_filter([$secret, $accessKey]) as $needle) {
            $message = str_replace($needle, '[redacted]', $message);
        }

        if (in_array($code, self::QUOTA_CODES, true)) {
            return CloudException::quotaReached($message !== '' ? $message : 'AWS capacity or quota reached.');
        }

        if (in_array($code, self::CREDENTIAL_CODES, true) || $status === 401) {
            return CloudException::invalidCredentials('AWS rejected the access key.');
        }

        // Missing instances surface as a 400; the callers recognise them by this marker.
        if (in_array($code, self::NOT_FOUND_CODES, true)) {
            return CloudException::api($message !== '' ? $message.' (does not exist)' : 'The instance does not exist.', 400);
        }

        return CloudException::api($message !== '' ? $message : "AWS EC2 request failed (HTTP {$status}).", $status);
    }

    private function parse(string $body): SimpleXMLElement
    {
        $xml = @simplexml_load_string((string) preg_replace('/ xmlns="[^"]*"/', '', $body));

        if (! $xml instanceof SimpleXMLElement) {
            throw CloudException::api('AWS returned an unexpected response.', 0);
        }

        return $xml;
    }

    private function mapInstance(SimpleXMLElement $instance, string $region): CloudServer
    {
        $labels = [];
        $name = '';
        foreach ($instance->tagSet->item ?? [] as $tag) {
            $key = (string) $tag->key;
            $labels[$key] = (string) $tag->value;
            if ($key === 'Name') {
                $name = (string) $tag->value;
            }
        }
        unset($labels['Name']);

        $launch = (string) $instance->launchTime;
        $ipv4 = (string) $instance->ipAddress;
        $ipv6 = (string) $instance->ipv6Address;

        return new CloudServer(
            id: $region.'/'.(string) $instance->instanceId,
            name: $name,
            status: $this->status((string) $instance->instanceState->name),
            ipv4: $ipv4 !== '' ? $ipv4 : null,
            ipv6: $ipv6 !== '' ? $ipv6 : null,
            createdAt: $launch !== '' ? CarbonImmutable::parse($launch) : null,
            labels: $labels,
        );
    }

    private function status(string $state): string
    {
        return match ($state) {
            'pending' => 'starting',
            'running' => 'running',
            'stopping', 'stopped', 'shutting-down', 'terminated' => 'stopping',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function tags(array $labels): array
    {
        $tags = [];

        foreach ($labels as $key => $value) {
            $tags[$this->tagValue($key)] = $this->tagValue($value);
        }

        return $tags;
    }

    /** Tag keys and values allow letters, digits, spaces and `+ - = . _ : / @`. */
    private function tagValue(string $value): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9 +\-=._:\/@]/', '-', $value), 0, 256);
    }

    /** @return array{0: string, 1: string} region and instance id */
    private function split(string $serverId): array
    {
        if (! str_contains($serverId, '/')) {
            throw CloudException::api("Unknown AWS server id [{$serverId}].", 0);
        }

        [$region, $id] = explode('/', $serverId, 2);

        return [$region, $id];
    }
}
