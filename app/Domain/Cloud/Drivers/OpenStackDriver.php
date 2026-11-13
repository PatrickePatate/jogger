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
 * OpenStack through Keystone v3 and Nova, for any OpenStack cloud (OVHcloud Public Cloud and others).
 *
 * Credentials: the Keystone auth URL (`auth_url`, https), an application credential id
 * (`application_credential_id`) and its secret (`token`). The template's location is the region; the compute
 * endpoint is read from the token's catalog. A server id is `{region}/{uuid}`. A token is requested for each
 * operation, so nothing expires between calls and nothing is cached.
 *
 * Template options: `network_ids` (list; "auto" is used without it), `security_groups` (names), `key_name`,
 * `availability_zone`. Floating IPs are not allocated: the network must give the server an address.
 */
final class OpenStackDriver implements CloudDriver
{
    /** Microversion that allows `"networks": "auto"`. */
    private const NOVA_MICROVERSION = '2.37';

    private const PAGE_SIZE = 100;

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(array $credentials): void
    {
        $session = $this->authenticate($credentials);

        if ($session['catalog'] === []) {
            throw CloudException::api('Keystone returned an empty service catalog.', 200);
        }
    }

    public function create(CloudProvider $provider, ServerSpec $spec): CloudServer
    {
        $region = $spec->location;
        $session = $this->authenticate($provider->credentials);
        $options = $spec->options;

        $networkIds = array_values(array_filter((array) ($options['network_ids'] ?? []), is_string(...)));
        $server = [
            'name' => $spec->name,
            'flavorRef' => $spec->serverType,
            'imageRef' => $spec->image,
            'user_data' => base64_encode($spec->userData),
            'metadata' => $this->metadata($spec->labels),
            'networks' => $networkIds === [] ? 'auto' : array_map(fn (string $id) => ['uuid' => $id], $networkIds),
        ];

        $groups = array_values(array_filter((array) ($options['security_groups'] ?? []), is_string(...)));
        if ($groups !== []) {
            $server['security_groups'] = array_map(fn (string $name) => ['name' => $name], $groups);
        }
        foreach (['key_name', 'availability_zone'] as $key) {
            if (is_string($options[$key] ?? null) && $options[$key] !== '') {
                $server[$key] = $options[$key];
            }
        }

        $response = $this->send($session, $region, 'post', '/servers', ['server' => $server]);

        if (! $response->successful()) {
            throw $this->failure($response, $provider->credentials, $session['token']);
        }

        $id = $response->json('server.id');
        if (! is_string($id) || $id === '') {
            throw CloudException::api('OpenStack returned an unexpected response.', $response->status());
        }

        // Nova answers with the id only; the addresses show up on later reads.
        return new CloudServer(
            id: $region.'/'.$id,
            name: $spec->name,
            status: 'starting',
            ipv4: null,
            ipv6: null,
            createdAt: CarbonImmutable::now(),
            labels: $spec->labels,
        );
    }

    public function get(CloudProvider $provider, string $serverId): ?CloudServer
    {
        [$region, $id] = $this->split($serverId);
        $session = $this->authenticate($provider->credentials);

        $response = $this->send($session, $region, 'get', '/servers/'.rawurlencode($id));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw $this->failure($response, $provider->credentials, $session['token']);
        }

        $server = $response->json('server');
        if (! is_array($server)) {
            throw CloudException::api('OpenStack returned an unexpected response.', $response->status());
        }

        // A deleted server can linger for a moment with this status.
        return in_array($server['status'] ?? null, ['DELETED', 'SOFT_DELETED'], true) ? null : $this->mapServer($server, $region);
    }

    public function delete(CloudProvider $provider, string $serverId): void
    {
        [$region, $id] = $this->split($serverId);
        $session = $this->authenticate($provider->credentials);

        $response = $this->send($session, $region, 'delete', '/servers/'.rawurlencode($id));

        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            throw $this->failure($response, $provider->credentials, $session['token']);
        }
    }

    public function listManaged(CloudProvider $provider, string $instanceId): array
    {
        $session = $this->authenticate($provider->credentials);
        $servers = [];

        foreach ($this->regions($provider) as $region) {
            $marker = null;

            do {
                $query = ['limit' => self::PAGE_SIZE] + ($marker !== null ? ['marker' => $marker] : []);
                $response = $this->send($session, $region, 'get', '/servers/detail', $query);

                if (! $response->successful()) {
                    throw $this->failure($response, $provider->credentials, $session['token']);
                }

                $page = array_values(array_filter((array) $response->json('servers'), is_array(...)));

                foreach ($page as $server) {
                    // Nova cannot filter on metadata for every user, so it is checked here.
                    $metadata = (array) ($server['metadata'] ?? []);
                    if (($metadata[self::MANAGED_BY_LABEL] ?? null) === 'jogger'
                        && ($metadata[self::INSTANCE_LABEL] ?? null) === $this->value($instanceId)) {
                        $servers[] = $this->mapServer($server, $region);
                    }
                }

                $last = $page === [] ? null : end($page)['id'] ?? null;
                $marker = count($page) === self::PAGE_SIZE && is_string($last) && $last !== $marker ? $last : null;
            } while ($marker !== null);
        }

        return $servers;
    }

    /** @return list<string> distinct regions used by this provider's templates */
    private function regions(CloudProvider $provider): array
    {
        return $provider->templates()->pluck('location')
            ->map(fn (mixed $region) => (string) $region)->unique()->values()->all();
    }

    /**
     * Gets a token from Keystone with the application credential.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{token: string, catalog: list<array<string, mixed>>}
     */
    private function authenticate(array $credentials): array
    {
        $authUrl = $this->string($credentials, 'auth_url');
        $id = $this->string($credentials, 'application_credential_id');
        $secret = $this->string($credentials, 'token');

        if (! str_starts_with(strtolower($authUrl), 'https://')) {
            throw CloudException::invalidCredentials('The Keystone auth URL must start with https://.');
        }

        try {
            $response = Http::acceptJson()->asJson()->timeout(20)
                ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->post(rtrim($authUrl, '/').'/auth/tokens', [
                    'auth' => ['identity' => [
                        'methods' => ['application_credential'],
                        'application_credential' => ['id' => $id, 'secret' => $secret],
                    ]],
                ]);
        } catch (ConnectionException) {
            throw CloudException::api('Could not reach Keystone.', 0);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw CloudException::invalidCredentials('OpenStack rejected the application credential.');
        }

        $token = $response->header('X-Subject-Token');
        if (! $response->successful() || $token === '') {
            throw $this->failure($response, $credentials, '');
        }

        return ['token' => $token, 'catalog' => array_values(array_filter((array) $response->json('token.catalog'), is_array(...)))];
    }

    /**
     * @param  array{token: string, catalog: list<array<string, mixed>>}  $session
     * @param  'get'|'post'|'delete'  $method
     * @param  array<string, mixed>  $payload  query string for GET, JSON body otherwise
     */
    private function send(array $session, string $region, string $method, string $path, array $payload = []): Response
    {
        $client = $this->client($session['token']);
        $url = $this->computeUrl($session['catalog'], $region).$path;

        try {
            return match ($method) {
                'get' => $client->get($url, $payload),
                'post' => $client->post($url, $payload),
                'delete' => $client->delete($url),
            };
        } catch (ConnectionException) {
            throw CloudException::api('Could not reach OpenStack.', 0);
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::withHeaders([
            'X-Auth-Token' => $token,
            'OpenStack-API-Version' => 'compute '.self::NOVA_MICROVERSION,
        ])->acceptJson()->asJson()->timeout(30)
            ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     */
    private function computeUrl(array $catalog, string $region): string
    {
        foreach ($catalog as $service) {
            if (($service['type'] ?? null) !== 'compute') {
                continue;
            }

            foreach ((array) ($service['endpoints'] ?? []) as $endpoint) {
                if (is_array($endpoint) && ($endpoint['interface'] ?? null) === 'public'
                    && ($endpoint['region_id'] ?? $endpoint['region'] ?? null) === $region
                    && is_string($endpoint['url'] ?? null)) {
                    return rtrim($endpoint['url'], '/');
                }
            }
        }

        throw CloudException::api("This OpenStack cloud has no compute endpoint for region [{$region}].", 0);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function failure(Response $response, array $credentials, string $token): CloudException
    {
        $status = $response->status();
        $body = $response->json();
        $message = '';

        // Nova wraps its errors as {"forbidden": {"message": "...", "code": 403}}; Keystone as {"error": {...}}.
        if (is_array($body)) {
            foreach ($body as $error) {
                if (is_array($error) && is_string($error['message'] ?? null)) {
                    $message = $error['message'];
                    break;
                }
            }
        }

        foreach (array_filter([$this->string($credentials, 'token'), $token]) as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        if ($status === 413 || ($status === 403 && stripos($message, 'quota') !== false)) {
            return CloudException::quotaReached($message !== '' ? $message : 'OpenStack quota reached.');
        }

        if ($status === 401) {
            return CloudException::invalidCredentials('OpenStack rejected the credentials.');
        }

        return CloudException::api($message !== '' ? $message : "OpenStack request failed (HTTP {$status}).", $status);
    }

    /** @param array<string, mixed> $server */
    private function mapServer(array $server, string $region): CloudServer
    {
        $created = $server['created'] ?? null;

        return new CloudServer(
            id: $region.'/'.(string) ($server['id'] ?? ''),
            name: (string) ($server['name'] ?? ''),
            status: $this->status((string) ($server['status'] ?? '')),
            ipv4: $this->address($server, 4),
            ipv6: $this->address($server, 6),
            createdAt: is_string($created) ? CarbonImmutable::parse($created) : null,
            labels: array_map(strval(...), array_filter((array) ($server['metadata'] ?? []), is_scalar(...))),
        );
    }

    private function status(string $status): string
    {
        return match ($status) {
            'BUILD', 'REBUILD' => 'starting',
            'ACTIVE' => 'running',
            'SHUTOFF', 'STOPPED', 'SOFT_DELETED', 'DELETED' => 'stopping',
            default => 'unknown',
        };
    }

    /** @param array<string, mixed> $server */
    private function address(array $server, int $version): ?string
    {
        foreach ((array) ($server['addresses'] ?? []) as $addresses) {
            foreach ((array) $addresses as $address) {
                if (is_array($address) && (int) ($address['version'] ?? 0) === $version
                    && is_string($address['addr'] ?? null) && $address['addr'] !== '') {
                    return $address['addr'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function metadata(array $labels): array
    {
        $metadata = [];

        foreach ($labels as $key => $value) {
            $metadata[substr($key, 0, 255)] = $this->value($value);
        }

        return $metadata;
    }

    private function value(string $value): string
    {
        return substr($value, 0, 255);
    }

    /** @return array{0: string, 1: string} region and server uuid */
    private function split(string $serverId): array
    {
        if (! str_contains($serverId, '/')) {
            throw CloudException::api("Unknown OpenStack server id [{$serverId}].", 0);
        }

        [$region, $id] = explode('/', $serverId, 2);

        return [$region, $id];
    }

    /** @param array<string, mixed> $credentials */
    private function string(array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
