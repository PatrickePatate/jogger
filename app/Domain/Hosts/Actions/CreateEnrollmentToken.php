<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Models\User;
use Illuminate\Support\Str;

final class CreateEnrollmentToken
{
    /**
     * Returns the plain token (shown once, never stored) and its record. Valid for one hour, usable once.
     *
     * @return array{0: string, 1: EnrollmentToken}
     */
    public function __invoke(User $creator, ?string $hostName = null): array
    {
        $plain = EnrollmentToken::PREFIX.Str::random(40);

        $token = EnrollmentToken::query()->create([
            'token_hash' => EnrollmentToken::hash($plain),
            'host_name' => $hostName,
            'expires_at' => now()->addMinutes(EnrollmentToken::TTL_MINUTES),
            'created_by' => $creator->id,
        ]);

        Audit::record('host.enrollment_token_created', $token, ['host_name' => $hostName], $creator);

        return [$plain, $token];
    }
}
