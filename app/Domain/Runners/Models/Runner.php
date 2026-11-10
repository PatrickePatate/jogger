<?php

declare(strict_types=1);

namespace App\Domain\Runners\Models;

use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Models\User;
use App\Support\Concerns\HasUuid7;
use Database\Factories\RunnerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One ephemeral runner: a container on a host, registered with GitHub for a single job. */
#[UseFactory(RunnerFactory::class)]
class Runner extends Model
{
    /** @use HasFactory<RunnerFactory> */
    use HasFactory, HasUuid7;

    public const NAME_PREFIX = 'jogger-';

    protected $guarded = [];

    protected $casts = [
        'state' => RunnerState::class,
        'github_runner_id' => 'integer',
        'exit_code' => 'integer',
        'state_changed_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<RunnerPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(RunnerPool::class, 'pool_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Host, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class)->withTrashed();
    }

    /**
     * The job this runner picked up, if any.
     *
     * @return HasOne<WorkflowJob, $this>
     */
    public function job(): HasOne
    {
        return $this->hasOne(WorkflowJob::class, 'runner_id');
    }

    /**
     * @param  Builder<Runner>  $query
     * @return Builder<Runner>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('state', RunnerState::values(RunnerState::live()));
    }

    /**
     * @param  Builder<Runner>  $query
     * @return Builder<Runner>
     */
    public function scopeInState(Builder $query, RunnerState ...$states): Builder
    {
        return $query->whereIn('state', RunnerState::values(array_values($states)));
    }

    /**
     * @param  Builder<Runner>  $query
     * @return Builder<Runner>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->whereIn('pool_id', RunnerPool::query()->visibleTo($user)->select('runner_pools.id'));
    }
}
