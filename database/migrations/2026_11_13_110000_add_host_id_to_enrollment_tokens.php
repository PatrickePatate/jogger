<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_tokens', function (Blueprint $table) {
            // A token minted for a cloud server names the host row created before the server booted.
            $table->foreignUuid('host_id')->nullable()->constrained('hosts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_id');
        });
    }
};
