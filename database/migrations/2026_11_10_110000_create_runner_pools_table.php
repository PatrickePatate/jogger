<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runner_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('installation_id')->constrained();
            $table->string('name', 100);
            $table->string('scope', 12);
            $table->string('scope_target', 200);
            $table->unsignedBigInteger('runner_group_id')->nullable();
            $table->string('image', 300);
            $table->decimal('cpu_limit', 5, 2)->nullable();
            $table->unsignedInteger('memory_limit_mb')->nullable();
            $table->unsignedInteger('min_idle')->default(0);
            $table->unsignedInteger('max_runners')->default(5);
            $table->unsignedInteger('idle_ttl_seconds')->default(900);
            $table->string('docker_mode', 8)->default('none');
            $table->boolean('persist_tool_cache')->default(false);
            $table->string('status', 8)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('ALTER TABLE runner_pools ADD COLUMN labels text[] NOT NULL DEFAULT \'{}\'');
        DB::statement('ALTER TABLE runner_pools ADD COLUMN allowed_host_labels text[] NOT NULL DEFAULT \'{}\'');
        DB::statement('CREATE INDEX runner_pools_labels_gin ON runner_pools USING gin (labels)');
        // Names are unique among live pools, so a deleted pool's name can be reused.
        DB::statement('CREATE UNIQUE INDEX runner_pools_installation_name ON runner_pools (installation_id, name) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE runner_pools ADD CONSTRAINT runner_pools_scope_check CHECK (scope IN ('repository','organization'))");
        DB::statement("ALTER TABLE runner_pools ADD CONSTRAINT runner_pools_status_check CHECK (status IN ('active','paused'))");
        DB::statement("ALTER TABLE runner_pools ADD CONSTRAINT runner_pools_docker_mode_check CHECK (docker_mode IN ('none','dind'))");
        DB::statement('ALTER TABLE runner_pools ADD CONSTRAINT runner_pools_capacity_check CHECK (max_runners >= min_idle AND max_runners >= 1)');
        DB::statement('ALTER TABLE workflow_jobs ADD CONSTRAINT workflow_jobs_pool_id_foreign FOREIGN KEY (pool_id) REFERENCES runner_pools (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_jobs DROP CONSTRAINT workflow_jobs_pool_id_foreign');
        Schema::dropIfExists('runner_pools');
    }
};
