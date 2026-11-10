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
        Schema::create('secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope_type', 12);
            $table->uuid('scope_id')->nullable();
            $table->string('kind', 8)->default('env');
            $table->string('name', 100);
            $table->text('value');                       // encrypted
            $table->text('previous_value')->nullable();  // encrypted; still masked in the logs of runners started before a rotation
            $table->timestamp('rotated_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id']);
        });
        DB::statement("ALTER TABLE secrets ADD CONSTRAINT secrets_scope_check CHECK ((scope_type = 'global' AND scope_id IS NULL) OR (scope_type IN ('installation','pool') AND scope_id IS NOT NULL))");
        DB::statement("ALTER TABLE secrets ADD CONSTRAINT secrets_kind_check CHECK (kind IN ('env','registry'))");
        // NULL scope_id values never collide in a plain unique index, so global names need their own.
        DB::statement('CREATE UNIQUE INDEX secrets_scoped_name_unique ON secrets (scope_type, scope_id, kind, name) WHERE scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX secrets_global_name_unique ON secrets (kind, name) WHERE scope_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
