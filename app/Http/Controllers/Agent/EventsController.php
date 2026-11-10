<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Actions\ApplyAgentEvent;
use App\Domain\Hosts\Models\Host;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventsController extends Controller
{
    /** Events are delivered at least once; handlers are idempotent, so a repeat is answered like the original. */
    public function __invoke(Request $request, ApplyAgentEvent $apply): JsonResponse
    {
        /** @var Host $host */
        $host = $request->attributes->get('host');

        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'runner_id' => ['required', 'uuid'],
            'data' => ['sometimes', 'nullable', 'array'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $handled = $apply->handle($host, $data['type'], $data['runner_id'], $data['data'] ?? []);

        return response()->json(['handled' => $handled], 202);
    }
}
