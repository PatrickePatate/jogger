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
        // One row per (type, subject): it fires, resolves, and fires again in place.
        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->string('subject', 100);
            $table->string('status', 8)->default('firing');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->timestamp('fired_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();   // last "fired" notice: start of the dedupe window
            $table->boolean('episode_notified')->default(false); // whether the current firing was announced (so its end is too)
            $table->timestamps();
            $table->unique(['type', 'subject']);
            $table->index('status');
        });
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_status_check CHECK (status IN ('firing','resolved'))");

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel', 8);
            $table->string('target', 500);                  // e-mail address or webhook URL
            $table->text('secret')->nullable();              // encrypted; signs webhook bodies
            $table->string('alert_type', 30)->nullable();    // null = every alert
            $table->boolean('enabled')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE notification_rules ADD CONSTRAINT notification_rules_channel_check CHECK (channel IN ('mail','webhook'))");

        // The in-app channel: what the bell shows to administrators.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('alert_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('kind', 8);                       // fired | resolved
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->jsonb('delivery_errors')->nullable();    // channel => message, when mail or webhook could not be sent
            $table->timestamp('created_at')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notifications_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notifications_read_at'));
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('alerts');
    }
};
