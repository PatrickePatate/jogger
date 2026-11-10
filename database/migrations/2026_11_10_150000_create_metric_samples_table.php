<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->timestamp('bucket');
            $table->string('resolution', 6);
            $table->string('subject_type', 8);
            // The all-zero UUID stands for "the whole instance", so the column can be part of the primary key.
            $table->uuid('subject_id')->default('00000000-0000-0000-0000-000000000000');
            $table->unsignedInteger('queue_depth')->default(0);
            $table->unsignedInteger('runners_busy')->nullable();
            $table->unsignedInteger('runners_idle')->nullable();
            $table->unsignedInteger('queue_wait_p50_ms')->nullable();
            $table->unsignedInteger('queue_wait_p95_ms')->nullable();
            $table->unsignedInteger('jobs_started')->default(0);
            $table->unsignedInteger('jobs_succeeded')->default(0);
            $table->unsignedInteger('jobs_failed')->default(0);
            $table->unsignedBigInteger('busy_seconds')->default(0);
            $table->primary(['resolution', 'subject_type', 'subject_id', 'bucket']);
        });
        DB::statement("ALTER TABLE metric_samples ADD CONSTRAINT metric_samples_resolution_check CHECK (resolution IN ('minute','hour'))");
        DB::statement("ALTER TABLE metric_samples ADD CONSTRAINT metric_samples_subject_check CHECK (subject_type IN ('global','pool','repo'))");
        // The rollup reads jobs by when they started and finished.
        DB::statement('CREATE INDEX workflow_jobs_started_at ON workflow_jobs (started_at) WHERE started_at IS NOT NULL');
        DB::statement('CREATE INDEX workflow_jobs_completed_at ON workflow_jobs (completed_at) WHERE completed_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS workflow_jobs_started_at');
        DB::statement('DROP INDEX IF EXISTS workflow_jobs_completed_at');
        Schema::dropIfExists('metric_samples');
    }
};
