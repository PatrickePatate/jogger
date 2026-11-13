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
 * DigitalOcean API v2 (https://api.digitalocean.com/v2). Servers are droplets; labels are stored as `key:value` tags.
 * A droplet always has a public IPv4 address, so the template's IPv4 switch has no effect here.
 */
final class DigitalOceanDriver implements CloudDriver
{
    private const BASE_URL = 'https://api.digitalocean.com/v2';

    /** Create-only fields passed through from the template's options when present. */
    private const OPTION_KEYS = ['ssh_keys', 'vpc_uuid', 'volumes'];

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(array $credentials): void
    {
        $token = $this->token($credentials);

        $response = $this->send($token, 'get', '/account');

        if (! $response->successful()) {
            throw $this->failure($response, $token);
        }
    }

    public function create(CloudProvider $provider, ServerSpec $spec): CloudServer
    {
        $token = $this->token($provider->credentials);

        $payload = [
            'name' => $spec->name,
            'region' => mb_strtolower($spec->location),
            'size' => mb_strtolower($spec->serverType),
            // Snapshots are addressed by numeric id, public images by slug.
            'image' => ctype_digit($spec->image) ? (int) $spec->image : $spec->image,
            'user_data' => $spec->userData,
            'tags' => $this->tags($spec->labels),
            'ipv6' => true,
        ];

        foreach (self::OPTION_KEYS as $key) {
            if (isset($spec->options[$key])) {
                $payload[$key] = $spec->options[$key];
            }
        }

        $response = $this->send($token, 'post', '/droplets', $payload);

        if (! $response->successful()) {
            throw $this->failure($response, $token);
        }

        return $this->serverFrom($response);
    }

    public function get(CloudProvider $provider, string $serverId): ?CloudServer
    {
        $token = $this->token($provider->credentials);

        $response = $this->send($token, 'get', '/droplets/'.rawurlencode($serverId));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw $this->failure($response, $token);
        }

        return $this->serverFrom($response);
    }

    public function delete(CloudProvider $provider, string $serverId): void
    {
        $token = $this->token($provider->credentials);

        $response = $this->send($token, 'delete', '/droplets/'.rawurlencode($serverId));

        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            throw $this->failure($response, $token);
        }
    }

    public function listManaged(CloudProvider $provider, string $instanceId): array
    {
        $token = $this->token($provider->credentials);
        $instanceTag = $this->tag(self::INSTANCE_LABEL, $instanceId);
        $managedTag = $this->tag(self::MANAGED_BY_LABEL, 'jogger');

        $servers = [];
        $page = 1;

        do {
            // The API filters on a single tag; the second one is checked here.
            $response = $this->send($token, 'get', '/droplets', [
                'tag_name' => $instanceTag,
                'per_page' => 200,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                throw $this->failure($response, $token);
            }

            foreach ((array) $response->json('droplets') as $droplet) {
                if (is_array($droplet) && in_array($managedTag, (array) ($droplet['tags'] ?? []), true)) {
                    $servers[] = $this->mapServer($droplet);
                }
            }

            $next = $response->json('links.pages.next');
            $page = is_string($next) && $next !== '' ? $page + 1 : null;
        } while ($page !== null);

        return $servers;
    }

    /**
     * @param  'get'|'post'|'delete'  $method
     * @param  array<string, mixed>  $payload  query string for GET, JSON body otherwise
     */
    private function send(string $token, string $method, string $path, array $payload = []): Response
    {
        $client = $this->client($token);

        try {
            return match ($method) {
                'get' => $client->get($path, $payload),
                'post' => $client->post($path, $payload),
                'delete' => $client->delete($path, $payload),
            };
        } catch (ConnectionException) {
            throw CloudException::api('Could not reach DigitalOcean.', 0);
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false);
    }

    private function failure(Response $response, string $token): CloudException
    {
        $status = $response->status();
        $rawMessage = $response->json('message');
        $message = is_string($rawMessage) ? $this->scrub($rawMessage, $token) : '';

        if (in_array($status, [401, 403], true)) {
            return CloudException::invalidCredentials('DigitalOcean rejected the API token.');
        }

        if ($status === 422 && preg_match('/droplet limit|exceed.*limit/i', $message) === 1) {
            return CloudException::quotaReached($message);
        }

        return CloudException::api(
            $message !== '' ? $message : "DigitalOcean request failed (HTTP {$status}).",
            $status,
        );
    }

    private function serverFrom(Response $response): CloudServer
    {
        $droplet = $response->json('droplet');

        if (! is_array($droplet)) {
            throw CloudException::api('DigitalOcean returned an unexpected response.', $response->status());
        }

        return $this->mapServer($droplet);
    }

    /** @param array<string, mixed> $droplet */
    private function mapServer(array $droplet): CloudServer
    {
        $created = $droplet['created_at'] ?? null;

        return new CloudServer(
            id: (string) ($droplet['id'] ?? ''),
            name: (string) ($droplet['name'] ?? ''),
            status: $this->status((string) ($droplet['status'] ?? '')),
            ipv4: $this->publicAddress($droplet, 'v4'),
            ipv6: $this->publicAddress($droplet, 'v6'),
            createdAt: is_string($created) ? CarbonImmutable::parse($created) : null,
            labels: $this->readTags($droplet['tags'] ?? null),
        );
    }

    private function status(string $status): string
    {
        return match ($status) {
            'new' => 'starting',
            'active' => 'running',
            'off', 'archive' => 'stopping',
            default => 'unknown',
        };
    }

    /** @param array<string, mixed> $droplet */
    private function publicAddress(array $droplet, string $family): ?string
    {
        foreach ((array) data_get($droplet, "networks.{$family}", []) as $network) {
            if (is_array($network) && ($network['type'] ?? null) === 'public'
                && is_string($network['ip_address'] ?? null) && $network['ip_address'] !== '') {
                return $network['ip_address'];
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

    /** Tags allow letters, digits, ':', '-' and '_' and at most 255 characters. */
    private function tag(string $key, string $value): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9:_-]/', '-', $key.':'.$value), 0, 255);
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

    /** @param array<string, mixed> $credentials */
    private function token(array $credentials): string
    {
        $token = $credentials['token'] ?? null;

        return is_string($token) ? $token : '';
    }

    private function scrub(string $message, string $token): string
    {
        return $token !== '' ? str_replace($token, '[redacted]', $message) : $message;
    }
}
