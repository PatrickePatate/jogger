<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Actions\RecordHeartbeat;
use App\Domain\Hosts\Models\Host;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HeartbeatController extends Controller
{
    public function __invoke(Request $request, RecordHeartbeat $record): JsonResponse
    {
        $data = $request->validate([
            'arch' => ['sometimes', 'nullable', 'string', 'max:10'],
            'os' => ['sometimes', 'nullable', 'string', 'max:20'],
            'docker_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'agent_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'cpu_total' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'cpu_used' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'memory_total_mb' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'memory_used_mb' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'disk_free_gb' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        /** @var Host $host */
        $host = $request->attributes->get('host');
        $host = $record($host, $data);

        return response()->json(['status' => $host->status->value, 'server_time' => now()->toIso8601String()]);
    }
}
