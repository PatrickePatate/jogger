<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Models;

use App\Support\Concerns\HasUuid7;
use Database\Factories\CloudProviderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account at a cloud provider. Credentials are stored encrypted and never serialized.
 *
 * @property array<string, mixed> $credentials
 */
#[UseFactory(CloudProviderFactory::class)]
class CloudProvider extends Model
{
    /** @use HasFactory<CloudProviderFactory> */
    use HasFactory, HasUuid7;

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
    ];

    /**
     * @return HasMany<HostTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(HostTemplate::class);
    }
}
