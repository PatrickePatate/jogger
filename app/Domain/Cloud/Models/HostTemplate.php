<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Models;

use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use App\Support\Concerns\HasUuid7;
use App\Support\Pg\PgTextArray;
use Database\Factories\HostTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a cloud server looks like and how many may exist for the pools it serves.
 *
 * @property list<string> $labels
 * @property array<string, mixed> $options
 */
#[UseFactory(HostTemplateFactory::class)]
class HostTemplate extends Model
{
    /** @use HasFactory<HostTemplateFactory> */
    use HasFactory, HasUuid7;

    protected $guarded = [];

    protected $casts = [
        'labels' => PgTextArray::class,
        'options' => 'array',
        'max_runners_per_host' => 'integer',
        'warm_hosts' => 'integer',
        'max_hosts' => 'integer',
        'idle_seconds' => 'integer',
        'max_lifetime_seconds' => 'integer',
        'ipv4' => 'boolean',
        'hourly_billing' => 'boolean',
        'hourly_price' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<CloudProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(CloudProvider::class, 'cloud_provider_id');
    }

    /**
     * @return BelongsToMany<RunnerPool, $this>
     */
    public function pools(): BelongsToMany
    {
        return $this->belongsToMany(RunnerPool::class, 'host_template_pool');
    }

    /**
     * @return HasMany<Host, $this>
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(Host::class);
    }
}
