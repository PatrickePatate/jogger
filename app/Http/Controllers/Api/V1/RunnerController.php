<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Audit;
use App\Domain\Runners\Actions\TerminateRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Http\Resources\RunnerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RunnerController
{
    /** Live runners by default; `state` picks one state, `pool` one pool. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'state' => ['sometimes', Rule::enum(RunnerState::class)],
            'pool' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return RunnerResource::collection(Runner::query()->visibleTo($request->user())
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('state', $state), fn ($q) => $q->live())
            ->when($filters['pool'] ?? null, fn ($q, $pool) => $q->where('pool_id', $pool))
            ->orderByDesc('id')->cursorPaginate((int) ($filters['per_page'] ?? 50)));
    }

    /** Terminates a runner. A busy runner needs `force=true`, because its job dies with it. */
    public function destroy(Request $request, string $runner, TerminateRunner $terminate): RunnerResource
    {
        abort_unless(Str::isUuid($runner), 404);
        $record = Runner::query()->visibleTo($request->user())->with('pool')->findOrFail($runner);
        Gate::authorize('update', $record->pool);

        abort_if($record->state === RunnerState::Busy && ! $request->boolean('force'), 409, 'The runner is busy; pass force=true to terminate it and its job.');

        if ($terminate($record, 'terminated_by_user')) {
            Audit::record('runner.terminated', $record, ['name' => $record->name, 'forced' => $request->boolean('force'), 'via' => 'api']);
        }

        return new RunnerResource($record->refresh());
    }
}
