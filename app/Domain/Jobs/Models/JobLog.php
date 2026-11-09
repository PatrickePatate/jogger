<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bookkeeping for one job's log file (the log text itself lives on the `logs` disk, see Task 27). */
class JobLog extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'job_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
        'line_count' => 'integer',
        'truncated' => 'boolean',
        'last_seq' => 'integer',
        'finalized_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WorkflowJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkflowJob::class, 'job_id');
    }
}
