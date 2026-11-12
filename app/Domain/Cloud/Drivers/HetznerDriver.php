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

/** Hetzner Cloud API (https://api.hetzner.cloud/v1). Never persists anything; the token never reaches an exception message. */
final class HetznerDriver implements CloudDriver
{
    private const BASE_URL = 'https://api.hetzner.cloud/v1';

    /** Error codes meaning the account or the location has no capacity left. */
    private const QUOTA_CODES = ['resource_limit_exceeded', 'limit_reached', 'resource_unavailable'];

    /** Create-only fields passed through from the template's options when present. */
    private const OPTION_KEYS = ['ssh_keys', 'firewalls', 'networks'];

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(array $credentials): void
    {
        $token = $this->token($credentials);

        $response = $this->send($token, 'get', '/locations', ['per_page' => 1]);

        if (! $response->successful()) {
            throw $this->failure($response, $token);
        }
    }

    public function create(CloudProvider $provider, ServerSpec $spec): CloudServer
    {
        $token = $this->token($provider->credentials);

        $payload = [
            'name' => $spec->name,
            'server_type' => mb_strtolower($spec->serverType), // Hetzner names are lower-case; "CCX33" is refused
            'location' => mb_strtolower($spec->location),
            // Snapshots are addressed by numeric id, public images by name.
            'image' => ctype_digit($spec->image) ? (int) $spec->image : $spec->image,
            'user_data' => $spec->userData,
            'labels' => $this->labels($spec->labels),
            'public_net' => ['enable_ipv4' => $spec->ipv4, 'enable_ipv6' => true],
            'start_after_create' => true,
        ];

        foreach (self::OPTION_KEYS as $key) {
            if (isset($spec->options[$key])) {
                $payload[$key] = $spec->options[$key];
            }
        }

        $response = $this->send($token, 'post', '/servers', $payload);

        if (! $response->successful()) {
            throw $this->failure($response, $token);
        }

        return $this->serverFrom($response);
    }

    public function get(CloudProvider $provider, string $serverId): ?CloudServer
    {
        $token = $this->token($provider->credentials);

        $response = $this->send($token, 'get', '/servers/'.rawurlencode($serverId));

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

        $response = $this->send($token, 'delete', '/servers/'.rawurlencode($serverId));

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
        $selector = self::MANAGED_BY_LABEL.'=jogger,'.self::INSTANCE_LABEL.'='.$this->labelValue($instanceId);

        $servers = [];
        $page = 1;

        do {
            $response = $this->send($token, 'get', '/servers', [
                'label_selector' => $selector,
                'per_page' => 50,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                throw $this->failure($response, $token);
            }

            foreach ((array) $response->json('servers') as $server) {
                if (is_array($server)) {
                    $servers[] = $this->mapServer($server);
                }
            }

            // Only follow a strictly increasing page number, so a misbehaving response cannot loop forever.
            $next = $response->json('meta.pagination.next_page');
            $page = is_int($next) && $next > $page ? $next : null;
        } while ($page !== null);

        return $servers;
    }

    /**
     * Sends one request. Connection errors are retried twice, then reported as a CloudException.
     *
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
            throw CloudException::api('Could not reach Hetzner Cloud.', 0);
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(
                2,
                200,
                fn (Throwable $e) => $e instanceof ConnectionException,
                throw: false,
            );
    }

    /**
     * Maps a non-successful response to the exception the scaler reacts to. The token is removed from any message.
     */
    private function failure(Response $response, string $token): CloudException
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $rawMessage = $response->json('error.message');
        $message = is_string($rawMessage) ? $this->scrub($rawMessage, $token) : '';

        if (is_string($code) && in_array($code, self::QUOTA_CODES, true)) {
            return CloudException::quotaReached($message !== '' ? $message : 'Hetzner Cloud quota reached.');
        }

        if (in_array($status, [401, 403], true)) {
            return CloudException::invalidCredentials('Hetzner Cloud rejected the API token.');
        }

        return CloudException::api(
            $message !== '' ? $message : "Hetzner Cloud request failed (HTTP {$status}).",
            $status,
        );
    }

    private function serverFrom(Response $response): CloudServer
    {
        $server = $response->json('server');

        if (! is_array($server)) {
            throw CloudException::api('Hetzner Cloud returned an unexpected response.', $response->status());
        }

        return $this->mapServer($server);
    }

    /** @param array<string, mixed> $server */
    private function mapServer(array $server): CloudServer
    {
        $created = $server['created'] ?? null;

        return new CloudServer(
            id: (string) ($server['id'] ?? ''),
            name: (string) ($server['name'] ?? ''),
            status: $this->status((string) ($server['status'] ?? '')),
            ipv4: $this->nullableString(data_get($server, 'public_net.ipv4.ip')),
            ipv6: $this->nullableString(data_get($server, 'public_net.ipv6.ip')),
            createdAt: is_string($created) ? CarbonImmutable::parse($created) : null,
            labels: $this->readLabels($server['labels'] ?? null),
        );
    }

    /** Maps Hetzner's server states onto the three states Jogger uses. */
    private function status(string $status): string
    {
        return match ($status) {
            'initializing', 'starting' => 'starting',
            'running' => 'running',
            'stopping', 'off', 'deleting' => 'stopping',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function labels(array $labels): array
    {
        $sanitised = [];

        foreach ($labels as $key => $value) {
            $sanitised[$key] = $this->labelValue($value);
        }

        return $sanitised;
    }

    /** Hetzner label values match [a-zA-Z0-9._-]{0,63}. */
    private function labelValue(string $value): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $value), 0, 63);
    }

    /** @return array<string, string> */
    private function readLabels(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        $out = [];

        foreach ($labels as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
