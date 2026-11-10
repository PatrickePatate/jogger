<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Actions;

use App\Domain\Jobs\Logs\SecretSource;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Models\Runner;
use App\Domain\Secrets\Contracts\ResolvesSecrets;
use App\Domain\Secrets\Enums\SecretKind;
use App\Domain\Secrets\Enums\SecretScope;
use App\Domain\Secrets\Models\Secret;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** What a pool's runners receive: the same name at a narrower scope wins (pool > installation > global). */
final class ResolveSecretsForPool implements ResolvesSecrets, SecretSource
{
    /** Bumped (forgotten) whenever a secret changes, so cached mask lists are not reused. */
    public const string MASK_VERSION = 'secrets:mask-version';

    public function envFor(RunnerPool $pool): array
    {
        $env = [];
        foreach ($this->effective($pool, SecretKind::Env) as $secret) {
            $env[$secret->name] = (string) $secret->value;
        }

        return $env;
    }

    /**
     * Credentials for the registry the pool's image lives on, if any were stored.
     *
     * @return array{server: string, username: string, password: string}|null
     */
    public function registryAuthFor(RunnerPool $pool): ?array
    {
        $server = self::registryOf($pool->image);
        $secret = $this->effective($pool, SecretKind::Registry)->firstWhere('name', $server);
        $credentials = $secret !== null ? json_decode((string) $secret->value, true) : null;

        return is_array($credentials) && isset($credentials['username'], $credentials['password'])
            ? ['server' => $server, 'username' => (string) $credentials['username'], 'password' => (string) $credentials['password']]
            : null;
    }

    /** Secret values (current and pre-rotation) of the runner's pool: what its log must not contain. */
    public function forRunner(string $runnerId): array
    {
        $version = Cache::rememberForever(self::MASK_VERSION, fn () => (string) hrtime(true));

        // Encrypted in the cache, like in the table.
        $sealed = Cache::remember("secrets:mask:{$version}:{$runnerId}", 300, function () use ($runnerId) {
            $pool = Runner::query()->with('pool')->find($runnerId)?->pool;
            $values = $pool === null ? [] : $this->scoped($pool)->flatMap(fn (Secret $s) => $s->sensitiveValues())->unique()->values()->all();

            return encrypt($values);
        });

        return decrypt($sealed);
    }

    /** `ghcr.io/org/image:tag` → `ghcr.io`; an image without a registry host lives on Docker Hub. */
    public static function registryOf(string $image): string
    {
        $first = explode('/', $image, 2);

        return count($first) === 2 && (str_contains($first[0], '.') || str_contains($first[0], ':') || $first[0] === 'localhost')
            ? strtolower($first[0])
            : 'docker.io';
    }

    /**
     * One secret per name, from the narrowest scope that defines it.
     *
     * @return Collection<int, Secret>
     */
    private function effective(RunnerPool $pool, SecretKind $kind): Collection
    {
        return $this->scoped($pool)->where('kind', $kind)
            ->sortByDesc(fn (Secret $s) => $s->scope_type->precedence())
            ->unique('name')->values();
    }

    /**
     * Every secret that applies to the pool, at any scope.
     *
     * @return Collection<int, Secret>
     */
    private function scoped(RunnerPool $pool): Collection
    {
        return Secret::query()
            ->where('scope_type', SecretScope::Global->value)
            ->orWhere(fn ($q) => $q->where('scope_type', SecretScope::Installation->value)->where('scope_id', $pool->installation_id))
            ->orWhere(fn ($q) => $q->where('scope_type', SecretScope::Pool->value)->where('scope_id', $pool->id))
            ->get();
    }
}
