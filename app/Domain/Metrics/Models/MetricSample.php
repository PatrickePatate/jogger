<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Models;

use Illuminate\Database\Eloquent\Model;

/** One time bucket of activity for the whole instance, one pool, or one repository. Written by RollupMetrics only. */
class MetricSample extends Model
{
    public const string MINUTE = 'minute';

    public const string HOUR = 'hour';

    public const string GLOBAL = 'global';

    public const string POOL = 'pool';

    public const string REPO = 'repo';

    public const string INSTANCE = '00000000-0000-0000-0000-000000000000';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected $casts = ['bucket' => 'datetime'];
}
