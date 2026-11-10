<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Actions\CreatePool;
use App\Domain\Pools\Actions\UpdatePool;
use App\Domain\Pools\Models\RunnerPool;
use App\Http\Resources\PoolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class PoolController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PoolResource::collection(
            RunnerPool::query()->visibleTo($request->user())->orderBy('name')->paginate(min(100, max(1, $request->integer('per_page', 50)))),
        );
    }

    public function show(Request $request, string $pool): PoolResource
    {
        return new PoolResource($this->find($request, $pool));
    }

    public function store(Request $request, CreatePool $create): JsonResponse
    {
        $installation = Installation::query()->visibleTo($request->user())->whereKey((string) $request->input('installation_id'))->first();
        if ($installation !== null) {
            Gate::authorize('createIn', [RunnerPool::class, $installation]);
        }

        // An installation the caller cannot see fails validation like one that does not exist.
        $input = $request->all();
        if ($installation === null) {
            $input['installation_id'] = '00000000-0000-0000-0000-000000000000';
        }

        return (new PoolResource($create($input)))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $pool, UpdatePool $update): PoolResource
    {
        $record = $this->find($request, $pool);
        Gate::authorize('update', $record);

        return new PoolResource($update($record, $request->all()));
    }

    private function find(Request $request, string $id): RunnerPool
    {
        abort_unless(str($id)->isUuid(), 404);

        return RunnerPool::query()->visibleTo($request->user())->findOrFail($id);
    }
}
