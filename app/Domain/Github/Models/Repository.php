<?php

declare(strict_types=1);

namespace App\Domain\Github\Models;

use App\Support\Concerns\HasUuid7;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
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
