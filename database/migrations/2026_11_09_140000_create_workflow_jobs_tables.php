<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 12)->default('github');
            $table->unsignedBigInteger('github_job_id')->unique();
            $table->unsignedBigInteger('github_run_id');
            $table->unsignedSmallInteger('run_attempt')->default(1);
            $table->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $table->string('workflow_name', 200)->nullable();
            $table->string('job_name', 200);
            $table->string('status', 12);
            $table->string('conclusion', 16)->nullable();
            $table->string('head_sha', 40)->nullable();
            $table->string('head_branch', 250)->nullable();
            $table->string('actor', 100)->nullable();
            $table->string('html_url', 500)->nullable();
            // Filled by the scheduler (Tasks 29-38). Foreign keys are added with the pools/runners tables.
            $table->uuid('pool_id')->nullable();
            $table->uuid('runner_id')->nullable();
            $table->string('unmatched_reason', 200)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'queued_at']);
            $table->index('github_run_id');
        });
        DB::statement('ALTER TABLE workflow_jobs ADD COLUMN labels text[] NOT NULL DEFAULT \'{}\'');
        DB::statement('CREATE INDEX workflow_jobs_repository_created ON workflow_jobs (repository_id, created_at DESC)');
        DB::statement('CREATE INDEX workflow_jobs_pool_created ON workflow_jobs (pool_id, created_at DESC) WHERE pool_id IS NOT NULL');
        DB::statement("ALTER TABLE workflow_jobs ADD CONSTRAINT workflow_jobs_status_check CHECK (status IN ('waiting','queued','in_progress','completed'))");
        DB::statement("ALTER TABLE workflow_jobs ADD CONSTRAINT workflow_jobs_conclusion_check CHECK (conclusion IS NULL OR conclusion IN ('success','failure','cancelled','skipped','timed_out','action_required','neutral'))");

        Schema::create('job_logs', function (Blueprint $table) {
            $table->foreignUuid('job_id')->primary()->constrained('workflow_jobs')->cascadeOnDelete();
            $table->string('storage_path', 300)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->boolean('truncated')->default(false);
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_logs');
        Schema::dropIfExists('workflow_jobs');
    }
};
