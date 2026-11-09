<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE team_user ADD CONSTRAINT team_user_role_check CHECK (role IN ('admin','operator','viewer'))");

        // What a team may access: an installation or a runner pool (grantable_type: installation | pool).
        Schema::create('team_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('grantable_type', 32);
            $table->uuid('grantable_id');
            $table->timestamps();
            $table->unique(['team_id', 'grantable_type', 'grantable_id']);
            $table->index(['grantable_type', 'grantable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_grants');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
