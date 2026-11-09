<?php

declare(strict_types=1);

namespace App\Domain\Github\Models;

use App\Support\Concerns\HasUuid7;
use Database\Factories\GithubAppFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The GitHub App Jogger is registered as. Secrets are encrypted at rest with the application key. */
#[UseFactory(GithubAppFactory::class)]
#[Hidden(['client_secret', 'private_key', 'webhook_secret'])]
class GithubApp extends Model
{
    /** @use HasFactory<GithubAppFactory> */
    use HasFactory, HasUuid7;

    public const DEFAULT_API_URL = 'https://api.github.com';

    protected $guarded = [];

    protected $casts = [
        'app_id' => 'integer',
        'client_secret' => 'encrypted',
        'private_key' => 'encrypted',
        'webhook_secret' => 'encrypted',
    ];

    /**
     * @return HasMany<Installation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
