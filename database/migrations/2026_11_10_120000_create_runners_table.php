<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pool_id')->constrained('runner_pools');
            $table->foreignUuid('host_id')->nullable()->constrained('hosts')->nullOnDelete();
            $table->unsignedBigInteger('github_runner_id')->nullable();
            $table->string('name', 100)->unique();
            $table->string('container_id', 80)->nullable();
            $table->string('state', 12)->default('provisioning');
            $table->string('exit_reason', 40)->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('state_changed_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE runners ADD CONSTRAINT runners_state_check CHECK (state IN ('provisioning','idle','busy','terminating','gone','failed'))");
        DB::statement("CREATE INDEX runners_pool_live ON runners (pool_id) WHERE state IN ('provisioning','idle','busy')");
        DB::statement("CREATE INDEX runners_host_live ON runners (host_id) WHERE state IN ('provisioning','idle','busy','terminating')");
        DB::statement('CREATE INDEX runners_state_changed ON runners (state, state_changed_at)');
        DB::statement('ALTER TABLE workflow_jobs ADD CONSTRAINT workflow_jobs_runner_id_foreign FOREIGN KEY (runner_id) REFERENCES runners (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_jobs DROP CONSTRAINT workflow_jobs_runner_id_foreign');
        Schema::dropIfExists('runners');
    }
};
