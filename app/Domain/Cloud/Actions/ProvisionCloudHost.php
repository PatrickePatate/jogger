<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Access\Audit;
use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Contracts\CloudDriver;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks a provider for one server for a template. The host row is made first, in state requested, and the enrollment
 * token names it, so the server's agent enrolls into that row. On success the row moves to booting; a refusal by the
 * provider deletes the row and its token, leaving nothing behind, and returns null.
 */
final class ProvisionCloudHost
{
    /** Label naming the template a server was made for. */
    public const TEMPLATE_LABEL = 'jogger-template';

    public function __construct(
        private readonly CloudManager $clouds,
        private readonly BuildUserData $userData,
        private readonly TransitionCloudHost $transition,
    ) {}

    /** The instance this Jogger installation labels its servers with. */
    public static function instanceId(): string
    {
        return (string) (config('jogger.cloud.instance_id') ?: substr(hash('sha256', (string) config('app.key')), 0, 12));
    }

    /**
     * @return Host|null the new host, or null when a cap is reached or the provider refused
     */
    public function __invoke(HostTemplate $template): ?Host
    {
        return DB::transaction(function () use ($template) {
            // Serializes every cloud creation, so the global cap below cannot be raced past by two templates.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['jogger-cloud-hosts']);
            $locked = HostTemplate::query()->whereKey($template->getKey())->lockForUpdate()->with('provider')->first();

            if ($locked === null || $locked->status !== 'active' || $locked->provider === null) {
                return null;
            }

            $open = CloudHostState::open();
            $ofTemplate = Host::query()->where('host_template_id', $locked->id)->whereIn('cloud_state', $open)->count();
            if ($ofTemplate >= $locked->max_hosts) {
                return null;
            }
            $ofAll = Host::query()->where('ephemeral', true)->whereIn('cloud_state', $open)->count();
            if ($ofAll >= (int) config('jogger.cloud.max_hosts')) {
                return null;
            }

            $name = 'cloud-'.Str::limit(Str::slug($locked->name), 60, '').'-'.Str::lower(Str::random(6));

            $host = Host::query()->create([
                'name' => $name,
                'status' => HostStatus::Pending,
                // Replaced by the agent's real credential at enrollment; this random value authenticates nothing.
                'credential_hash' => Host::hashCredential(Host::CREDENTIAL_PREFIX.Str::random(48)),
                'labels' => $locked->labels,
                'ephemeral' => true,
                'host_template_id' => $locked->id,
                'cloud_state' => CloudHostState::Requested,
                'cloud_state_changed_at' => now(),
            ]);

            $plain = EnrollmentToken::PREFIX.Str::random(40);
            $token = EnrollmentToken::query()->create([
                'token_hash' => EnrollmentToken::hash($plain),
                'host_name' => $name,
                'host_id' => $host->id,
                'expires_at' => now()->addSeconds((int) config('jogger.cloud.boot_timeout_seconds') + 60),
            ]);

            $spec = new ServerSpec(
                name: $name,
                serverType: $locked->server_type,
                location: $locked->location,
                image: $locked->image,
                userData: ($this->userData)($locked, $plain, $name),
                ipv4: $locked->ipv4,
                labels: [
                    CloudDriver::MANAGED_BY_LABEL => 'jogger',
                    CloudDriver::INSTANCE_LABEL => self::instanceId(),
                    self::TEMPLATE_LABEL => $locked->id,
                ],
                options: $locked->options,
            );

            try {
                $server = $this->clouds->for($locked->provider)->create($locked->provider, $spec);
            } catch (CloudException $e) {
                $token->delete();
                $host->forceDelete();
                Audit::record('cloud.host_create_failed', $locked, ['name' => $name, 'reason' => $e->reason, 'message' => mb_substr($e->getMessage(), 0, 300)]);

                return null;
            }

            ($this->transition)($host, CloudHostState::Booting, ['provider_server_id' => $server->id, 'billed_from' => now()]);
            Audit::record('cloud.host_created', $host, ['name' => $name, 'template' => $locked->id, 'server' => $server->id]);

            return $host->refresh();
        });
    }
}
