<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 14g — Auction Insights: the Google Ads API does not return auction insights, so an operator uploads the
 * report exported from Google Ads (CSV). One row per competitor domain per upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_auction_insights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->uuid('upload_id')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('domain');
            $table->boolean('is_own')->default(false);
            $table->decimal('impression_share', 8, 4)->nullable();
            $table->decimal('overlap_rate', 8, 4)->nullable();
            $table->decimal('position_above_rate', 8, 4)->nullable();
            $table->decimal('top_of_page_rate', 8, 4)->nullable();
            $table->decimal('abs_top_rate', 8, 4)->nullable();
            $table->decimal('outranking_share', 8, 4)->nullable();
            // Metrics Google shows as "< 10%": stored as 0.1 and listed here so the screen can say "<%10".
            $table->json('below_threshold')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['digital_asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_auction_insights');
    }
};
