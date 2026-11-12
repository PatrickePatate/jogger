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
        Schema::create('cloud_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->string('driver', 40);
            $table->text('credentials');                 // encrypted
            $table->string('status', 12)->default('active');
            $table->timestamps();
        });

        Schema::create('host_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cloud_provider_id')->constrained('cloud_providers')->cascadeOnDelete();
            $table->string('name', 100)->unique();
            $table->string('server_type', 60);
            $table->string('location', 60);
            $table->string('image', 200);
            $table->unsignedSmallInteger('max_runners_per_host')->default(1);
            $table->unsignedSmallInteger('warm_hosts')->default(0);
            $table->unsignedSmallInteger('max_hosts')->default(1);
            $table->unsignedInteger('idle_seconds')->default(300);
            $table->unsignedInteger('max_lifetime_seconds')->default(86400);
            $table->boolean('ipv4')->default(true);
            $table->boolean('hourly_billing')->default(true);
            $table->decimal('hourly_price', 8, 4)->nullable();
            $table->jsonb('options')->default('{}');     // provider-specific fields
            $table->string('status', 12)->default('active');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE host_templates ADD COLUMN labels text[] NOT NULL DEFAULT \'{}\'');

        Schema::create('host_template_pool', function (Blueprint $table) {
            $table->foreignUuid('host_template_id')->constrained('host_templates')->cascadeOnDelete();
            $table->foreignUuid('runner_pool_id')->constrained('runner_pools')->cascadeOnDelete();
            $table->primary(['host_template_id', 'runner_pool_id']);
        });

        Schema::table('hosts', function (Blueprint $table) {
            $table->foreignUuid('host_template_id')->nullable()->constrained('host_templates')->nullOnDelete();
            $table->string('provider_server_id', 100)->nullable();
            $table->boolean('ephemeral')->default(false);
            $table->string('cloud_state', 12)->nullable();
            $table->timestamp('cloud_state_changed_at')->nullable();
            $table->timestamp('billed_from')->nullable();
            $table->index(['host_template_id', 'cloud_state']);
        });
        DB::statement("ALTER TABLE hosts ADD CONSTRAINT hosts_cloud_state_check CHECK (cloud_state IS NULL OR cloud_state IN ('requested','booting','online','draining','deleting','deleted','failed'))");
    }

    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->dropIndex(['host_template_id', 'cloud_state']);
            $table->dropConstrainedForeignId('host_template_id');
            $table->dropColumn(['provider_server_id', 'ephemeral', 'cloud_state', 'cloud_state_changed_at', 'billed_from']);
        });
        Schema::dropIfExists('host_template_pool');
        Schema::dropIfExists('host_templates');
        Schema::dropIfExists('cloud_providers');
    }
};
