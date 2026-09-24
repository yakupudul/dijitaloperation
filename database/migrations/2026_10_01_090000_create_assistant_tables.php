<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 6 (Asistan): phone push channels (ntfy / Telegram) on agency settings and a sent log; site uptime
 * checks and state; renewals (domain / hosting / SSL / other) with fee and collection state; reminders; a
 * per-user calendar feed token; prospect follow-up date; WhatsApp conversation ↔ customer / prospect link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->string('push_ntfy_url', 500)->nullable();
            $table->text('push_ntfy_token')->nullable();
            $table->text('push_telegram_bot_token')->nullable();
            $table->string('push_telegram_chat_id', 64)->nullable();
            $table->string('push_min_severity', 10)->default('high');
        });

        Schema::create('push_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('dedupe_key', 191)->index();
            $table->string('channel', 20);
            $table->string('severity', 10);
            $table->string('title', 255);
            $table->text('body');
            $table->string('status', 20);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('uptime_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at')->index();
        });

        Schema::create('uptime_states', function (Blueprint $table): void {
            $table->foreignId('digital_asset_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('state', 10)->default('unknown');
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('down_since')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_up_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('asset_renewals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('digital_asset_id')->nullable()->index();
            $table->string('kind', 20);
            $table->string('label', 255);
            $table->string('provider', 160)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('expires_source', 20)->default('manual');
            $table->boolean('auto_renew')->default(false);
            $table->decimal('cost_amount', 12, 2)->nullable();
            $table->decimal('charge_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('TRY');
            $table->string('collection_status', 20)->default('not_billed');
            $table->text('notes')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['expires_on']);
        });

        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('prospect_id')->nullable();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->timestamp('remind_at')->index();
            $table->string('repeat', 10)->default('none');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('calendar_feed_token', 64)->nullable()->unique();
        });

        Schema::table('prospects', function (Blueprint $table): void {
            $table->date('next_follow_up_on')->nullable();
            $table->string('next_step', 255)->nullable();
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('prospect_id')->nullable()->index();
            $table->string('link_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['prospect_id']);
            $table->dropColumn(['customer_id', 'prospect_id', 'link_source']);
        });
        Schema::table('prospects', function (Blueprint $table): void {
            $table->dropColumn(['next_follow_up_on', 'next_step']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['calendar_feed_token']);
            $table->dropColumn('calendar_feed_token');
        });
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('asset_renewals');
        Schema::dropIfExists('uptime_states');
        Schema::dropIfExists('uptime_checks');
        Schema::dropIfExists('push_notifications');
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['push_ntfy_url', 'push_ntfy_token', 'push_telegram_bot_token', 'push_telegram_chat_id', 'push_min_severity']);
        });
    }
};
