<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('target_type', 40)->nullable();
            $table->uuid('target_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('created_at');
            $table->index(['target_type', 'target_id']);
            $table->index('user_id');
        });
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN ip TYPE inet USING ip::inet');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique(); // sha256 of the plain token; the plain token is never stored
            $table->string('role', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE personal_access_tokens ADD CONSTRAINT personal_access_tokens_role_check CHECK (role IN ('admin','operator','viewer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('audit_logs');
    }
};
