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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Scaleway Instances API (https://api.scaleway.com/instance/v1). Credentials are a secret key and a project id.
 * Servers belong to a zone, so the template's location is a zone (`fr-par-1`) and a server id is `{zone}/{uuid}`.
 * Labels are stored as `key:value` tags. Orphan listing looks at the zones of this provider's templates.
 */
final class ScalewayDriver implements CloudDriver
{
    private const BASE_URL = 'https://api.scaleway.com/instance/v1/zones';

    /** Zone used to check credentials; the key is not zone-scoped. */
    private const CHECK_ZONE = 'fr-par-1';

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(array $credentials): void
    {
        $key = $this->secretKey($credentials);

        $response = $this->send($key, 'get', self::CHECK_ZONE, '/servers', [
            'project' => $this->projectId($credentials),
            'per_page' => 1,
        ]);

        if (! $response->successful()) {
            throw $this->failure($response, $key);
        }
    }

    public function create(CloudProvider $provider, ServerSpec $spec): CloudServer
    {
        $key = $this->secretKey($provider->credentials);
        $zone = mb_strtolower($spec->location);

        $response = $this->send($key, 'post', $zone, '/servers', [
            'name' => $spec->name,
            'project' => $this->projectId($provider->credentials),
            'commercial_type' => strtoupper($spec->serverType),
            'image' => $spec->image,
            'tags' => $this->tags($spec->labels),
            'enable_ipv6' => true,
            'dynamic_ip_required' => $spec->ipv4,
        ]);

        if (! $response->successful()) {
            throw $this->failure($response, $key);
        }

        $server = $this->serverFrom($response, $zone);

        // User data and power-on are separate calls; a half-built server must not be left behind.
        try {
            $this->putUserData($key, $zone, $server->id, $spec->userData);
            $this->powerOn($key, $zone, $server->id);
        } catch (CloudException $e) {
            try {
                $this->delete($provider, $server->id);
            } catch (CloudException) {
                // Reconciliation removes it later.
            }

            throw $e;
        }

        return $server;
    }

    public function get(CloudProvider $provider, string $serverId): ?CloudServer
    {
        $key = $this->secretKey($provider->credentials);
        [$zone, $id] = $this->split($serverId);

        $response = $this->send($key, 'get', $zone, '/servers/'.rawurlencode($id));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw $this->failure($response, $key);
        }

        return $this->serverFrom($response, $zone);
    }

    public function delete(CloudProvider $provider, string $serverId): void
    {
        $key = $this->secretKey($provider->credentials);
        [$zone, $id] = $this->split($serverId);

        $response = $this->send($key, 'post', $zone, '/servers/'.rawurlencode($id).'/action', ['action' => 'terminate']);

        // 404: already gone. 400 "precondition failed" on a server that is already being terminated is not retried either.
        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            throw $this->failure($response, $key);
        }
    }

    public function listManaged(CloudProvider $provider, string $instanceId): array
    {
        $key = $this->secretKey($provider->credentials);
        $tags = implode(',', [
            $this->tag(self::MANAGED_BY_LABEL, 'jogger'),
            $this->tag(self::INSTANCE_LABEL, $instanceId),
        ]);

        $servers = [];

        foreach ($this->zones($provider) as $zone) {
            $page = 1;

            do {
                $response = $this->send($key, 'get', $zone, '/servers', [
                    'project' => $this->projectId($provider->credentials),
                    'tags' => $tags,
                    'per_page' => 50,
                    'page' => $page,
                ]);

                if (! $response->successful()) {
                    throw $this->failure($response, $key);
                }

                $found = (array) $response->json('servers');
                foreach ($found as $server) {
                    if (is_array($server)) {
                        $servers[] = $this->mapServer($server, $zone);
                    }
                }

                $page = count($found) === 50 ? $page + 1 : null;
            } while ($page !== null);
        }

        return $servers;
    }

    /** @return list<string> distinct zones used by this provider's templates */
    private function zones(CloudProvider $provider): array
    {
        return $provider->templates()->pluck('location')
            ->map(fn (mixed $zone) => mb_strtolower((string) $zone))
            ->unique()->values()->all();
    }

    private function putUserData(string $key, string $zone, string $serverId, string $userData): void
    {
        [, $id] = $this->split($serverId);

        $response = $this->client($key)
            ->withBody($userData, 'text/plain')
            ->put("/{$zone}/servers/".rawurlencode($id).'/user_data/cloud-init');

        if (! $response->successful()) {
            throw $this->failure($response, $key);
        }
    }

    private function powerOn(string $key, string $zone, string $serverId): void
    {
        [, $id] = $this->split($serverId);

        $response = $this->send($key, 'post', $zone, '/servers/'.rawurlencode($id).'/action', ['action' => 'poweron']);

        if (! $response->successful()) {
            throw $this->failure($response, $key);
        }
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload  query string for GET, JSON body otherwise
     */
    private function send(string $key, string $method, string $zone, string $path, array $payload = []): Response
    {
        try {
            $client = $this->client($key);
            $url = "/{$zone}{$path}";

            return $method === 'get' ? $client->get($url, $payload) : $client->post($url, $payload);
        } catch (ConnectionException) {
            throw CloudException::api('Could not reach Scaleway.', 0);
        }
    }

    private function client(string $key): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-Auth-Token' => $key])
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false);
    }

    private function failure(Response $response, string $key): CloudException
    {
        $status = $response->status();
        $type = $response->json('type');
        $rawMessage = $response->json('message');
        $message = is_string($rawMessage) ? $this->scrub($rawMessage, $key) : '';

        if ($type === 'quotas_exceeded') {
            return CloudException::quotaReached($message !== '' ? $message : 'Scaleway quota reached.');
        }

        if ($status === 401 || in_array($type, ['denied_authentication', 'invalid_auth'], true)) {
            return CloudException::invalidCredentials('Scaleway rejected the secret key.');
        }

        return CloudException::api(
            $message !== '' ? $message : "Scaleway request failed (HTTP {$status}).",
            $status,
        );
    }

    private function serverFrom(Response $response, string $zone): CloudServer
    {
        $server = $response->json('server');

        if (! is_array($server)) {
            throw CloudException::api('Scaleway returned an unexpected response.', $response->status());
        }

        return $this->mapServer($server, $zone);
    }

    /** @param array<string, mixed> $server */
    private function mapServer(array $server, string $zone): CloudServer
    {
        $created = $server['creation_date'] ?? null;

        return new CloudServer(
            id: $zone.'/'.(string) ($server['id'] ?? ''),
            name: (string) ($server['name'] ?? ''),
            status: $this->status((string) ($server['state'] ?? '')),
            ipv4: $this->ipv4($server),
            ipv6: $this->nullableString(data_get($server, 'ipv6.address')),
            createdAt: is_string($created) ? CarbonImmutable::parse($created) : null,
            labels: $this->readTags($server['tags'] ?? null),
        );
    }

    private function status(string $state): string
    {
        return match ($state) {
            'starting' => 'starting',
            'running' => 'running',
            'stopping', 'stopped', 'stopped in place' => 'stopping',
            default => 'unknown',
        };
    }

    /** @param array<string, mixed> $server */
    private function ipv4(array $server): ?string
    {
        $legacy = $this->nullableString(data_get($server, 'public_ip.address'));
        if ($legacy !== null) {
            return $legacy;
        }

        foreach ((array) ($server['public_ips'] ?? []) as $ip) {
            if (is_array($ip) && ($ip['family'] ?? 'inet') === 'inet') {
                return $this->nullableString($ip['address'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<string>
     */
    private function tags(array $labels): array
    {
        $tags = [];

        foreach ($labels as $key => $value) {
            $tags[] = $this->tag($key, $value);
        }

        return $tags;
    }

    /** Tags are free text; commas would break the list filter, so they are replaced. */
    private function tag(string $key, string $value): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9:._-]/', '-', $key.':'.$value), 0, 255);
    }

    /** @return array<string, string> */
    private function readTags(mixed $tags): array
    {
        $labels = [];

        foreach ((array) $tags as $tag) {
            if (is_string($tag) && str_contains($tag, ':')) {
                [$key, $value] = explode(':', $tag, 2);
                $labels[$key] = $value;
            }
        }

        return $labels;
    }

    /** @return array{0: string, 1: string} zone and server uuid */
    private function split(string $serverId): array
    {
        if (! str_contains($serverId, '/')) {
            throw CloudException::api("Unknown Scaleway server id [{$serverId}].", 0);
        }

        [$zone, $id] = explode('/', $serverId, 2);

        return [$zone, $id];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $credentials */
    private function secretKey(array $credentials): string
    {
        $key = $credentials['token'] ?? null;

        return is_string($key) ? $key : '';
    }

    /** @param array<string, mixed> $credentials */
    private function projectId(array $credentials): string
    {
        $id = $credentials['project_id'] ?? null;

        return is_string($id) ? $id : '';
    }

    private function scrub(string $message, string $key): string
    {
        return $key !== '' ? str_replace($key, '[redacted]', $message) : $message;
    }
}
