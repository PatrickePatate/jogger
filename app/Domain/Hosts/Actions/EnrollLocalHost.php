<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;

/**
 * The optional agent on the server's own machine (compose profile `local-host`). Both containers read the same
 * JOGGER_LOCAL_HOST_TOKEN from .env; this registers it as a one-time enrollment token so the agent can enroll
 * itself on first start. Off unless JOGGER_LOCAL_HOST=true: mounting the Docker socket next to the control plane
 * is a deliberate choice.
 */
final class EnrollLocalHost
{
    public const HOST_NAME = 'local';

    /** @return 'disabled'|'invalid_token'|'already_enrolled'|'ready' */
    public function __invoke(): string
    {
        if (! config('jogger.local_host_enabled')) {
            return 'disabled';
        }

        $plain = (string) config('jogger.local_host_token');
        if (! str_starts_with($plain, EnrollmentToken::PREFIX) || strlen($plain) < 36) {
            return 'invalid_token';
        }

        $hash = EnrollmentToken::hash($plain);
        $used = EnrollmentToken::query()->where('token_hash', $hash)->whereNotNull('used_at')->exists();
        $exists = Host::query()->where('name', self::HOST_NAME)->where('status', '!=', HostStatus::Revoked->value)->exists();
        if ($used || $exists) {
            return 'already_enrolled';
        }

        // Re-running install only extends the window.
        $token = EnrollmentToken::query()->updateOrCreate(
            ['token_hash' => $hash],
            ['host_name' => self::HOST_NAME, 'expires_at' => now()->addDay()],
        );
        if ($token->wasRecentlyCreated) {
            Audit::record('host.local_enrollment_prepared', $token);
        }

        return 'ready';
    }
}
