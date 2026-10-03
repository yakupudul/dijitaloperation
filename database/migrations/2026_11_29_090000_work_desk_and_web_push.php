<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Genel işler (yakup, 2026-10-03):
 *  - suggestions.verification / verified_at: after "Yaptım" (or when a system check passes by itself) the next data
 *    pull confirms the change: pending → confirmed | still_seen; auto = the system noticed the fix without a click.
 *  - push_subscriptions + web_push_keys: phone / browser notifications (Web Push, VAPID) for the important work
 *    alerts; push_notifications.url is the screen the notification opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            if (! Schema::hasColumn('suggestions', 'verification')) {
                $table->string('verification', 16)->nullable(); // pending | confirmed | still_seen | auto
                $table->timestampTz('verified_at')->nullable();
            }
        });
        if (! Schema::hasColumn('push_notifications', 'url')) {
            Schema::table('push_notifications', fn (Blueprint $table) => $table->string('url', 500)->nullable());
        }
        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('endpoint_hash', 64)->unique();
                $table->text('endpoint');
                $table->string('public_key', 200)->nullable();
                $table->string('auth_token', 100)->nullable();
                $table->string('device', 160)->nullable();
                $table->unsignedSmallInteger('failures')->default(0);
                $table->timestampTz('last_sent_at')->nullable();
                $table->timestampsTz();
            });
        }
        if (! Schema::hasTable('web_push_keys')) {
            Schema::create('web_push_keys', function (Blueprint $table): void {
                $table->id();
                $table->string('public_key', 200);
                $table->text('private_key');
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_keys');
        Schema::dropIfExists('push_subscriptions');
        if (Schema::hasColumn('push_notifications', 'url')) {
            Schema::table('push_notifications', fn (Blueprint $table) => $table->dropColumn('url'));
        }
        if (Schema::hasColumn('suggestions', 'verification')) {
            Schema::table('suggestions', fn (Blueprint $table) => $table->dropColumn(['verification', 'verified_at']));
        }
    }
};
