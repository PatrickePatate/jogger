<?php

declare(strict_types=1);

namespace App\Domain\Github\Gateway;

use App\Domain\Github\Models\Installation;
use App\Support\Exceptions\GithubApiError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Installation access tokens (valid 1 hour), minted with the app JWT and cached ~55 minutes. */
final class InstallationTokenStore
{
    /** Never keep a token longer than this, even if GitHub says it lives longer. */
    private const MAX_TTL_SECONDS = 55 * 60;

    /** Drop a token this long before GitHub's stated expiry. */
    private const EXPIRY_MARGIN_SECONDS = 5 * 60;

    public function __construct(private readonly AppJwt $jwt, private readonly GithubHttp $http) {}

    public function get(Installation $installation): string
    {
        $key = $this->key($installation);

        if (($cached = $this->read($key)) !== null) {
            return $cached;
        }

        // One process mints; concurrent callers wait and then read the fresh token.
        return Cache::lock("{$key}.mint", 15)->block(10, function () use ($installation, $key): string {
            return $this->read($key) ?? $this->mint($installation, $key);
        });
    }

    public function forget(Installation $installation): void
    {
        Cache::forget($this->key($installation));
    }

    private function mint(Installation $installation, string $key): string
    {
        $app = $installation->app;

        try {
            $response = $this->http->pending($app, $this->jwt->for($app))
                ->post("/app/installations/{$installation->github_installation_id}/access_tokens");
        } catch (ConnectionException $e) {
            throw $this->http->unreachable($e);
        }

        if (! $response->successful()) {
            $this->http->failure($response);
        }

        $token = (string) $response->json('token');
        $expiresAt = $response->json('expires_at');

        if ($token === '') {
            throw new GithubApiError('GitHub returned no installation token.', $response->status());
        }

        $lifetime = $expiresAt
            ? CarbonImmutable::parse($expiresAt)->timestamp - CarbonImmutable::now()->timestamp - self::EXPIRY_MARGIN_SECONDS
            : self::MAX_TTL_SECONDS;

        Cache::put($key, Crypt::encryptString($token), max(60, min(self::MAX_TTL_SECONDS, $lifetime)));

        return $token;
    }

    private function read(string $key): ?string
    {
        $stored = Cache::get($key);

        return is_string($stored) ? Crypt::decryptString($stored) : null;
    }

    private function key(Installation $installation): string
    {
        return "github.installation_token.{$installation->github_installation_id}";
    }
}
