<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kazananlar (the race between brands per service), rebuilt daily by rules:
 * - ad_service_stats.top_campaign / currency: the campaign bringing most of a service's results on that channel and the
 *   account currency (the 2.000 TL threshold applies to TRY accounts);
 * - web_service_stats: a brand service on the brand's website over 30 days (search clicks, impressions and average
 *   position of the service's cluster queries, pages linked to the service and the strongest page);
 * - gbp_profile_stats: a Business Profile's rating, review count, new reviews in 30 days and listed services;
 * - ad_winner_snapshots: one row per day and catalog service with every channel's leader and the overall ranking, for
 *   "yükselenler / düşenler" and "liderlik değişimleri".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_service_stats') && ! Schema::hasColumn('ad_service_stats', 'top_campaign')) {
            Schema::table('ad_service_stats', function (Blueprint $table): void {
                $table->string('top_campaign', 300)->nullable();
                $table->string('currency', 8)->nullable();
            });
        }
        if (! Schema::hasTable('web_service_stats')) {
            Schema::create('web_service_stats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->unsignedBigInteger('brand_offering_id');
                $table->unsignedBigInteger('service_id')->nullable();
                $table->unsignedBigInteger('sector_id')->nullable();
                $table->string('city', 80)->default('');
                $table->unsignedInteger('pages')->default(0);
                $table->unsignedInteger('clicks')->default(0);
                $table->unsignedInteger('impressions')->default(0);
                $table->decimal('position', 6, 1)->nullable();
                $table->string('top_url', 600)->nullable();
                $table->unsignedInteger('top_clicks')->nullable();
                $table->date('period_end');
                $table->timestamps();
                $table->index(['service_id'], 'web_service_stats_service_idx');
                $table->index(['brand_id'], 'web_service_stats_brand_idx');
            });
        }
        if (! Schema::hasTable('gbp_profile_stats')) {
            Schema::create('gbp_profile_stats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('digital_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('name', 300)->nullable();
                $table->string('city', 80)->default('');
                $table->decimal('rating', 3, 2)->nullable();
                $table->unsignedInteger('reviews')->default(0);
                $table->unsignedInteger('new_reviews')->default(0);
                $table->json('labels')->nullable(); // services listed on the profile
                $table->json('service_ids')->nullable(); // catalog services of the brand matched to those labels
                $table->date('period_end');
                $table->timestamps();
                $table->index(['brand_id'], 'gbp_profile_stats_brand_idx');
            });
        }
        if (! Schema::hasTable('ad_winner_snapshots')) {
            Schema::create('ad_winner_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('service_id');
                $table->date('snapshot_date');
                $table->json('leaders'); // {channel: {brand_id, value}}
                $table->json('ranking'); // [{brand_id, score, rank}]
                $table->timestamps();
                $table->unique(['service_id', 'snapshot_date'], 'ad_winner_snapshots_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_winner_snapshots');
        Schema::dropIfExists('gbp_profile_stats');
        Schema::dropIfExists('web_service_stats');
        if (Schema::hasTable('ad_service_stats') && Schema::hasColumn('ad_service_stats', 'top_campaign')) {
            Schema::table('ad_service_stats', function (Blueprint $table): void {
                $table->dropColumn(['top_campaign', 'currency']);
            });
        }
    }
};
