<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Models;

use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Models\User;
use App\Support\Concerns\HasUuid7;
use App\Support\Pg\PgTextArray;
use Database\Factories\WorkflowJobFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Jogger's copy of a GitHub Actions job. (Named WorkflowJob, table workflow_jobs, to stay clear of Laravel's queue "Job".)
 */
#[UseFactory(WorkflowJobFactory::class)]
class WorkflowJob extends Model
{
    /** @use HasFactory<WorkflowJobFactory> */
    use HasFactory, HasUuid7;

    protected $guarded = [];

    protected $casts = [
        'github_job_id' => 'integer',
        'github_run_id' => 'integer',
        'run_attempt' => 'integer',
        'labels' => PgTextArray::class,
        'status' => JobStatus::class,
        'conclusion' => JobConclusion::class,
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return HasOne<JobLog, $this>
     */
    public function log(): HasOne
    {
        return $this->hasOne(JobLog::class, 'job_id');
    }

    /**
     * Jobs of repositories whose installation the user may see (team grants), or everything for global admins.
     *
     * @param  Builder<WorkflowJob>  $query
     * @return Builder<WorkflowJob>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('repository_id', Repository::query()->visibleTo($user)->select('repositories.id'));
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
