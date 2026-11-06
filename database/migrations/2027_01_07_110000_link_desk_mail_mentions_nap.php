<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlink hizmeti, adım 3 (2026-11-06):
 * 1. link_messages: every e-mail that left the Bağlantı masası (first mail and follow-up), to whom, what, who clicked.
 * 2. backlink_sources.followup_at (when the follow-up went) and nap (the listing's phone / address against the Business
 *    Profile).
 * 3. brands.link_alert_feed: the brand's Google Alerts RSS feed; pages that name the brand without a link become
 *    anılma rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('link_messages')) {
            Schema::create('link_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('backlink_source_id')->constrained('backlink_sources')->cascadeOnDelete();
                $table->string('kind', 12); // ilk | takip
                $table->string('to', 255);
                $table->string('subject', 200);
                $table->text('body');
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('sent_at')->nullable();
                $table->string('error', 500)->nullable();
                $table->timestampsTz();
            });
        }
        if (! Schema::hasColumn('backlink_sources', 'followup_at')) {
            Schema::table('backlink_sources', function (Blueprint $table): void {
                $table->timestampTz('followup_at')->nullable();
                $table->json('nap')->nullable();
            });
        }
        if (! Schema::hasColumn('brands', 'link_alert_feed')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->text('link_alert_feed')->nullable();
                $table->timestampTz('link_alert_read_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('link_messages');
        if (Schema::hasColumn('backlink_sources', 'followup_at')) {
            Schema::table('backlink_sources', fn (Blueprint $table) => $table->dropColumn(['followup_at', 'nap']));
        }
        if (Schema::hasColumn('brands', 'link_alert_feed')) {
            Schema::table('brands', fn (Blueprint $table) => $table->dropColumn(['link_alert_feed', 'link_alert_read_at']));
        }
    }
};
