<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->string('status', 12)->default('pending');
            $table->string('arch', 10)->nullable();
            $table->string('os', 20)->nullable();
            $table->string('docker_version', 20)->nullable();
            $table->decimal('cpu_total', 6, 2)->nullable();
            $table->unsignedInteger('memory_total_mb')->nullable();
            $table->decimal('cpu_used', 6, 2)->nullable();
            $table->unsignedInteger('memory_used_mb')->nullable();
            $table->unsignedInteger('disk_free_gb')->nullable();
            $table->string('agent_version', 20)->nullable();
            $table->string('credential_hash', 128)->nullable()->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('ALTER TABLE hosts ADD COLUMN labels text[] NOT NULL DEFAULT \'{}\'');
        DB::statement("ALTER TABLE hosts ADD CONSTRAINT hosts_status_check CHECK (status IN ('pending','online','offline','cordoned','draining','revoked'))");

        Schema::create('enrollment_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash', 128)->unique();
            $table->string('host_name', 100)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignUuid('used_by_host_id')->nullable()->constrained('hosts')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_tokens');
        Schema::dropIfExists('hosts');
    }
};
