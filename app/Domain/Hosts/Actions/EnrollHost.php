<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use App\Support\Exceptions\InvalidEnrollmentToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnrollHost
{
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

    private function uniqueName(string $wanted): string
    {
        $name = $wanted;

        while (Host::withTrashed()->where('name', $name)->exists()) {
            $name = Str::limit($wanted, 93, '').'-'.Str::lower(Str::random(5));
        }

        return $name;
    }
}
