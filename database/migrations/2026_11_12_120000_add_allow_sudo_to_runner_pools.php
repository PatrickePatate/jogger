<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_pools', function (Blueprint $table) {
            $table->boolean('allow_sudo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('runner_pools', function (Blueprint $table) {
            $table->dropColumn('allow_sudo');
        });
    }
};
