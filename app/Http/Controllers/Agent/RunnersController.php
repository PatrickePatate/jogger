<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RunnersController extends Controller
{
    /**
     * Every runner this host should still have a container for. The agent removes Jogger containers that are
     * not in this list, so it must be complete: a runner still being provisioned is included.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Host $host */
        $host = $request->attributes->get('host');

        return response()->json([
            'runners' => Runner::query()->where('host_id', $host->id)->inState(...RunnerState::present())->orderBy('id')->pluck('id'),
        ]);
    }
}
