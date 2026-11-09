<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_apps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('app_id');
            $table->string('slug', 100);
            $table->string('client_id', 64);
            $table->text('client_secret');   // encrypted
            $table->text('private_key');     // encrypted
            $table->text('webhook_secret');  // encrypted
            $table->string('api_base_url', 255)->default('https://api.github.com');
            $table->timestamps();
            $table->unique('app_id');
        });

        Schema::create('installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('github_app_id')->constrained('github_apps')->cascadeOnDelete();
            $table->unsignedBigInteger('github_installation_id')->unique();
            $table->string('account_login', 100);
            $table->string('account_type', 8);
            $table->string('status', 12)->default('active');
            $table->string('repository_selection', 8);
            $table->timestamp('synced_at')->nullable(); // last full repository sync from the API
            $table->timestamps();
            $table->index('github_app_id');
        });
        DB::statement("ALTER TABLE installations ADD CONSTRAINT installations_account_type_check CHECK (account_type IN ('user','org'))");
        DB::statement("ALTER TABLE installations ADD CONSTRAINT installations_status_check CHECK (status IN ('active','suspended','deleted'))");
        DB::statement("ALTER TABLE installations ADD CONSTRAINT installations_selection_check CHECK (repository_selection IN ('all','selected'))");

        Schema::create('repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_repo_id')->unique();
            $table->string('full_name', 200);
            $table->boolean('private');
            $table->timestamps();
            $table->index(['installation_id', 'full_name']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('github_app_id')->nullable()->constrained('github_apps')->nullOnDelete();
            $table->uuid('delivery_id')->unique(); // X-GitHub-Delivery
            $table->string('event', 40);
            $table->string('action', 40)->nullable();
            $table->jsonb('payload');
            $table->string('status', 12)->default('received');
            $table->text('error')->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('replayed_at')->nullable();
            $table->unsignedSmallInteger('replay_count')->default(0);
            $table->index('received_at');
            $table->index(['status', 'received_at']);
        });
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('received','processing','processed','failed','ignored'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('installations');
        Schema::dropIfExists('github_apps');
    }
};
