<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Hosts\Actions\ApplyCommandResult;
use App\Domain\Hosts\Enums\CommandStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommandsController extends Controller
{
    /** Hands the agent its pending commands, oldest first, and marks them sent. */
    public function index(Request $request): JsonResponse
    {
        /** @var Host $host */
        $host = $request->attributes->get('host');

        $commands = DB::transaction(function () use ($host) {
            $due = HostCommand::query()
                ->where('host_id', $host->id)
                ->where('status', CommandStatus::Pending->value)
                ->where('available_at', '<=', now())
                ->orderBy('created_at')->orderBy('id')
                ->limit(50)
                ->lock('for update skip locked')
                ->get();

            if ($due->isNotEmpty()) {
                HostCommand::query()->whereIn('id', $due->modelKeys())->update([
                    'status' => CommandStatus::Sent->value,
                    'attempts' => DB::raw('attempts + 1'),
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $due;
        });

        return response()->json([
            'commands' => $commands->map(fn (HostCommand $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'payload' => $c->payload,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** The agent reports the outcome of a command. Re-sending the same ack is harmless. */
    public function ack(Request $request, string $command, ApplyCommandResult $apply): JsonResponse
    {
        /** @var Host $host */
        $host = $request->attributes->get('host');

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'result' => ['sometimes', 'nullable', 'array'],
        ]);

        // Commands of other hosts are indistinguishable from commands that do not exist.
        $record = Str::isUuid($command)
            ? HostCommand::query()->where('host_id', $host->id)->whereKey($command)->first()
            : null;
        abort_if($record === null, 404);

        $final = $data['ok'] ? CommandStatus::Acked : CommandStatus::Failed;

        // The single-use runner credential has no business staying in the table once the command is settled.
        $payload = $record->payload;
        unset($payload['jit_config']);

        $settled = HostCommand::query()
            ->whereKey($record->id)
            ->whereIn('status', [CommandStatus::Pending->value, CommandStatus::Sent->value])
            ->update([
                'status' => $final->value,
                'payload' => json_encode($payload),
                'result' => isset($data['result']) ? json_encode($data['result']) : null,
                'acked_at' => now(),
                'updated_at' => now(),
            ]);

        // Only the ack that settled the command has consequences; a repeated ack is just answered.
        if ($settled > 0) {
            $apply($host, $record, (bool) $data['ok'], $data['result'] ?? null);
        }

        return response()->json(['id' => $record->id, 'status' => $record->refresh()->status->value]);
    }
}
