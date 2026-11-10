<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Logs arrive per runner container, often before GitHub tells us which job that runner picked up, so the
     * bookkeeping row is keyed by runner and linked to its job once known.
     */
    public function up(): void
    {
        DB::statement('DELETE FROM job_logs');
        DB::statement('ALTER TABLE job_logs DROP CONSTRAINT job_logs_pkey');
        DB::statement('ALTER TABLE job_logs ALTER COLUMN job_id DROP NOT NULL');
        DB::statement('ALTER TABLE job_logs ADD COLUMN runner_id uuid PRIMARY KEY');
        DB::statement('ALTER TABLE job_logs ADD COLUMN host_id uuid NULL');
        DB::statement('ALTER TABLE job_logs ADD COLUMN last_chunk_at timestamp(0) NULL');
        DB::statement('CREATE UNIQUE INDEX job_logs_job_id_unique ON job_logs (job_id) WHERE job_id IS NOT NULL');
        DB::statement('CREATE INDEX job_logs_expires_at ON job_logs (expires_at) WHERE expires_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM job_logs');
        DB::statement('DROP INDEX job_logs_job_id_unique');
        DB::statement('DROP INDEX job_logs_expires_at');
        DB::statement('ALTER TABLE job_logs DROP COLUMN runner_id, DROP COLUMN host_id, DROP COLUMN last_chunk_at');
        DB::statement('ALTER TABLE job_logs ALTER COLUMN job_id SET NOT NULL');
        DB::statement('ALTER TABLE job_logs ADD PRIMARY KEY (job_id)');
    }
};
