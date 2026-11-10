<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Logs\LogIngestor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogsController extends Controller
{
    /** One batch of a runner's output. A batch we already have is answered as a success so the agent moves on. */
    public function __invoke(Request $request, string $runner, LogIngestor $ingestor): JsonResponse
    {
        /** @var Host $host */
        $host = $request->attributes->get('host');

        $data = $request->validate([
            'seq' => ['required', 'integer', 'min:1'],
            'data' => ['present', 'nullable', 'string', 'max:1048576'],
            'eof' => ['sometimes', 'boolean'],
        ]);

        // The global TrimStrings middleware strips the whitespace a log depends on, so the text is read from the raw body.
        $raw = json_decode($request->getContent(), true);
        $text = is_array($raw) && is_string($raw['data'] ?? null) ? $raw['data'] : '';

        $result = $ingestor->ingest($host, $runner, (int) $data['seq'], $text, (bool) ($data['eof'] ?? false));
        abort_if($result === null, 404);

        return response()->json($result);
    }
}
