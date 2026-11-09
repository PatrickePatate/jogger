<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var PersonalAccessToken $token */
        $token = $request->attributes->get('access_token');

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'token' => ['id' => $token->id, 'name' => $token->name, 'role' => $token->role->value],
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
