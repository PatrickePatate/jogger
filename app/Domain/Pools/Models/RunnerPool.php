<?php

declare(strict_types=1);

namespace App\Domain\Pools\Models;

use App\Domain\Access\Concerns\VisibleTo;
use App\Domain\Access\Contracts\Grantable;
use App\Domain\Github\Data\RunnerScope;
use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Data\ScalePolicyData;
use App\Domain\Pools\Enums\DockerMode;
use App\Domain\Pools\Enums\PoolStatus;
use App\Models\User;
use App\Support\Concerns\HasUuid7;
use App\Support\Pg\PgTextArray;
use Database\Factories\RunnerPoolFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A set of interchangeable ephemeral runners: where they register, what they look like, how many may exist.
 *
 * @property list<string> $labels
 * @property list<string> $allowed_host_labels
 */
#[UseFactory(RunnerPoolFactory::class)]
class RunnerPool extends Model implements Grantable
{
    /** @use HasFactory<RunnerPoolFactory> */
    use HasFactory, HasUuid7, SoftDeletes, VisibleTo {
        scopeVisibleTo as scopeGrantedTo;
    }

    protected $guarded = [];

    protected $casts = [
        'scope' => ScopeType::class,
        'status' => PoolStatus::class,
        'docker_mode' => DockerMode::class,
        'labels' => PgTextArray::class,
        'allowed_host_labels' => PgTextArray::class,
        'cpu_limit' => 'float',
        'memory_limit_mb' => 'integer',
        'min_idle' => 'integer',
        'max_runners' => 'integer',
        'idle_ttl_seconds' => 'integer',
        'runner_group_id' => 'integer',
        'persist_tool_cache' => 'boolean',
        'allow_sudo' => 'boolean',
    ];

    /**
     * @return BelongsTo<Installation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function grantableType(): string
    {
        return 'pool';
    }

    /**
     * Pools a team was granted directly, plus every pool of an installation it was granted.
     *
     * @param  Builder<RunnerPool>  $query
     * @return Builder<RunnerPool>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('runner_pools.id', self::query()->grantedTo($user)->select('runner_pools.id'))
            ->orWhereIn('runner_pools.installation_id', Installation::query()->visibleTo($user)->select('installations.id')));
    }

    public function isPaused(): bool
    {
        return $this->status === PoolStatus::Paused;
    }

    public function githubScope(): RunnerScope
    {
        return new RunnerScope($this->installation, $this->scope, $this->scope_target, $this->runner_group_id);
    }

    public function scalePolicy(): ScalePolicyData
    {
        return new ScalePolicyData($this->min_idle, $this->max_runners, $this->idle_ttl_seconds);
    }
}
