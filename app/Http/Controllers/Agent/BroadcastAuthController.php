<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Models\Host;
use App\Http\Controllers\Controller;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\Response;

/** Signs a Reverb channel subscription for an agent. A host may only ever join its own private channel. */
final class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var Host $host */
        $host = $request->attributes->get('host');

        $request->validate(['channel_name' => ['required', 'string'], 'socket_id' => ['required', 'string']]);
        abort_unless($request->input('channel_name') === "private-host.{$host->id}", 403);

        /** @var PusherBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection();

        return response()->json($broadcaster->validAuthenticationResponse($request, true));
    }
}
