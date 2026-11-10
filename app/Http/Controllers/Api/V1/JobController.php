<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Http\Resources\JobResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class JobController
{
    /** Newest first, cursor paginated. Filters: repository (owner/name), status, pool (id). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'repository' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', Rule::enum(JobStatus::class)],
            'pool' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $jobs = WorkflowJob::query()->visibleTo($request->user())->with('repository')
            ->when($filters['repository'] ?? null, fn ($q, $name) => $q->whereIn('repository_id', Repository::query()->where('full_name', $name)->select('id')))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['pool'] ?? null, fn ($q, $pool) => $q->where('pool_id', $pool))
            ->orderByDesc('id') // UUIDv7: id order is creation order
            ->cursorPaginate((int) ($filters['per_page'] ?? 50));

        return JobResource::collection($jobs);
    }

    public function show(Request $request, string $job): JobResource
    {
        abort_unless(Str::isUuid($job), 404);

        return new JobResource(WorkflowJob::query()->visibleTo($request->user())->with('repository')->findOrFail($job));
    }
}
