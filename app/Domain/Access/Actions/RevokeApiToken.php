<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Models\PersonalAccessToken;

final class RevokeApiToken
{
    public function __invoke(PersonalAccessToken $token): void
    {
        Audit::record('token.revoked', $token, ['name' => $token->name, 'owner_id' => $token->user_id]);

        $token->delete();
    }
}
