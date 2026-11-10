<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('host_id')->constrained()->cascadeOnDelete();
            $table->string('image', 300);
            $table->string('status', 8)->default('pending');
            $table->text('error')->nullable();
            $table->uuid('command_id')->nullable()->index();
            $table->timestamp('pulled_at')->nullable();
            $table->timestamps();
            $table->unique(['host_id', 'image']);
        });
        DB::statement("ALTER TABLE host_images ADD CONSTRAINT host_images_status_check CHECK (status IN ('pending','ready','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('host_images');
    }
};
