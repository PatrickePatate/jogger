<?php

declare(strict_types=1);

namespace App\Domain\Github\Models;

use App\Models\User;
use App\Support\Concerns\HasUuid7;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(RepositoryFactory::class)]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory, HasUuid7;

    protected $guarded = [];

    protected $casts = [
        'github_repo_id' => 'integer',
        'private' => 'boolean',
    ];

    /**
     * @return BelongsTo<Installation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    /**
     * Repositories of installations the user may see (team grants); global admins see all.
     *
     * @param  Builder<Repository>  $query
     * @return Builder<Repository>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('installation_id', Installation::query()->visibleTo($user)->select('installations.id'));
    }

    /** "owner/name" split into its two halves. */
    public function owner(): string
    {
        return explode('/', $this->full_name, 2)[0];
    }

    public function name(): string
    {
        return explode('/', $this->full_name, 2)[1] ?? $this->full_name;
    }
}
