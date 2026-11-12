<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Actions\EnrollHost;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollController extends Controller
{
    public function __invoke(Request $request, EnrollHost $enroll): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'hostname' => ['required', 'string', 'max:100'],
            'facts' => ['sometimes', 'array'],
            'facts.arch' => ['sometimes', 'nullable', 'string', 'max:10'],
            'facts.os' => ['sometimes', 'nullable', 'string', 'max:20'],
            'facts.docker_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'facts.cpu_total' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'facts.memory_total_mb' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'facts.disk_free_gb' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'facts.agent_version' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        [$host, $credential] = $enroll($data['token'], $data['hostname'], $data['facts'] ?? []);

        // Agents are outside the server's network: give them the address browsers use (the public one, or the
        // app's own URL when the proxy serves websockets on it), never the internal broadcasting host.
        /** @var array<string, mixed> $public */
        $public = array_filter((array) config('jogger.reverb_public'));
        $app = parse_url((string) config('app.url')) ?: [];
        $scheme = (string) ($public['scheme'] ?? $app['scheme'] ?? 'https');
        $reverb = [
            'host' => (string) ($public['host'] ?? $app['host'] ?? 'localhost'),
            'port' => (int) ($public['port'] ?? $app['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'scheme' => $scheme,
        ];

        return response()->json([
            'host_id' => $host->id,
            'name' => $host->name,
            'credential' => $credential,
            'reverb' => [
                'host' => $reverb['host'],
                'port' => $reverb['port'],
                'scheme' => $reverb['scheme'],
                'key' => config('broadcasting.connections.reverb.key'),
            ],
        ], 201);
    }
}
