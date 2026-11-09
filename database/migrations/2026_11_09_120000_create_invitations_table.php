<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email', 255);
            $table->boolean('is_admin')->default(false);
            $table->foreignUuid('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 16)->nullable();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IS NULL OR role IN ('admin','operator','viewer'))");
        // At most one open invitation per email.
        DB::statement('CREATE UNIQUE INDEX invitations_pending_email ON invitations (email) WHERE accepted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
