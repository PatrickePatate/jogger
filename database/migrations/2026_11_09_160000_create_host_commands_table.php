<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('host_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->jsonb('payload');
            $table->string('status', 8)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->jsonb('result')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE host_commands ADD CONSTRAINT host_commands_status_check CHECK (status IN ('pending','sent','acked','failed'))");
        DB::statement("CREATE INDEX host_commands_pending ON host_commands (host_id, available_at) WHERE status IN ('pending','sent')");
    }

    public function down(): void
    {
        Schema::dropIfExists('host_commands');
    }
};
