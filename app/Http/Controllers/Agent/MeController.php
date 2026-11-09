<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Models\Host;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lets an agent confirm its credential is still accepted. */
final class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Host $host */
        $host = $request->attributes->get('host');

        return response()->json(['host_id' => $host->id, 'name' => $host->name, 'status' => $host->status->value]);
    }
}
