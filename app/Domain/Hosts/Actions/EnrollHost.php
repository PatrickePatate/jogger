<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Cloud\Actions\TransitionCloudHost;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use App\Support\Exceptions\InvalidEnrollmentToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnrollHost
{
    /** Facts an agent reports about its machine; labels come from the template for cloud hosts. */
    private const FACTS = ['arch', 'os', 'docker_version', 'cpu_total', 'memory_total_mb', 'disk_free_gb', 'agent_version'];

    public function __construct(private readonly TransitionCloudHost $transition) {}

    /**
     * Burns the token and creates the host in one transaction. The claim is a conditional UPDATE, so two agents
     * racing with the same token cannot both win. Returns the host and its plain credential (shown once).
     *
     * @param  array<string, mixed>  $facts  arch, os, docker_version, cpu_total, memory_total_mb, disk_free_gb, agent_version, labels
     * @return array{0: Host, 1: string}
     *
     * @throws InvalidEnrollmentToken
     */
    public function __invoke(string $plainToken, string $hostname, array $facts = []): array
    {
        return DB::transaction(function () use ($plainToken, $hostname, $facts) {
            $claimed = EnrollmentToken::query()
                ->where('token_hash', EnrollmentToken::hash($plainToken))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now(), 'updated_at' => now()]);

            if ($claimed === 0) {
                throw new InvalidEnrollmentToken;
            }

            $token = EnrollmentToken::query()->where('token_hash', EnrollmentToken::hash($plainToken))->firstOrFail();
            $plain = Host::CREDENTIAL_PREFIX.Str::random(48);

            if ($token->host_id !== null) {
                return $this->enrollCloudHost($token, $plain, $facts);
            }

            $host = Host::query()->create([
                'name' => $this->uniqueName($token->host_name ?? $hostname),
                'status' => HostStatus::Pending,
                'credential_hash' => Host::hashCredential($plain),
                'arch' => $facts['arch'] ?? null,
                'os' => $facts['os'] ?? null,
                'docker_version' => $facts['docker_version'] ?? null,
                'cpu_total' => $facts['cpu_total'] ?? null,
                'memory_total_mb' => $facts['memory_total_mb'] ?? null,
                'disk_free_gb' => $facts['disk_free_gb'] ?? null,
                'agent_version' => $facts['agent_version'] ?? null,
                'labels' => $facts['labels'] ?? [],
            ]);

            $token->forceFill(['used_by_host_id' => $host->id])->save();
            Audit::record('host.enrolled', $host, ['name' => $host->name, 'token_id' => $token->id]);

            return [$host, $plain];
        });
    }

    /**
     * A cloud server's host row exists since before it booted (ProvisionCloudHost made it). Enrolling it gives the row
     * its credential and facts, and moves it from booting to online. It stays pending until the first heartbeat.
     *
     * @param  array<string, mixed>  $facts
     * @return array{0: Host, 1: string}
     *
     * @throws InvalidEnrollmentToken
     */
    private function enrollCloudHost(EnrollmentToken $token, string $plain, array $facts): array
    {
        $host = Host::query()->whereKey($token->host_id)->lockForUpdate()->first();
        if ($host === null || $host->cloud_state !== CloudHostState::Booting) {
            // Reaped (boot timeout) or already enrolled: the token is spent either way.
            throw new InvalidEnrollmentToken;
        }

        $host->forceFill(array_intersect_key($facts, array_flip(self::FACTS)) + ['credential_hash' => Host::hashCredential($plain)])->save();

        if (! ($this->transition)($host, CloudHostState::Online)) {
            throw new InvalidEnrollmentToken;
        }

        $token->forceFill(['used_by_host_id' => $host->id])->save();
        Audit::record('host.enrolled', $host, ['name' => $host->name, 'token_id' => $token->id, 'cloud' => true]);

        return [$host, $plain];
    }

    private function uniqueName(string $wanted): string
    {
        $name = $wanted;

        while (Host::withTrashed()->where('name', $name)->exists()) {
            $name = Str::limit($wanted, 93, '').'-'.Str::lower(Str::random(5));
        }

        return $name;
    }
}
