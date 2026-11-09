<?php

declare(strict_types=1);

namespace App\Domain\Github\Models;

use App\Domain\Access\Concerns\VisibleTo;
use App\Domain\Access\Contracts\Grantable;
use App\Domain\Github\Enums\AccountType;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Enums\RepositorySelection;
use App\Support\Concerns\HasUuid7;
use Database\Factories\InstallationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A GitHub App installation on a user or organization account; teams can be granted access to it. */
#[UseFactory(InstallationFactory::class)]
class Installation extends Model implements Grantable
{
    /** @use HasFactory<InstallationFactory> */
    use HasFactory, HasUuid7, VisibleTo;

    protected $guarded = [];

    protected $casts = [
        'github_installation_id' => 'integer',
        'account_type' => AccountType::class,
        'status' => InstallationStatus::class,
        'repository_selection' => RepositorySelection::class,
        'synced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<GithubApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class, 'github_app_id');
    }

    /**
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function isActive(): bool
    {
        return $this->status === InstallationStatus::Active;
    }
}
