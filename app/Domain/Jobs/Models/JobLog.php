<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bookkeeping for one runner's log file (the text itself lives on the log disk). Keyed by runner; `job_id` is filled
 * once the runner is known to have picked up a job.
 *
 * @property string $runner_id
 * @property string|null $host_id
 * @property string|null $job_id
 * @property Carbon|null $last_chunk_at
 */
class JobLog extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'runner_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
        'line_count' => 'integer',
        'truncated' => 'boolean',
        'last_seq' => 'integer',
        'finalized_at' => 'datetime',
        'last_chunk_at' => 'datetime',
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
