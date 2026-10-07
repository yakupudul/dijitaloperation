<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rule-built ad numbers for comparisons across brands (no AI):
 * - ad_service_stats: one brand service's spend and results of one result type on one channel over the last 30 days
 *   (Meta: campaign → hizmet, split per ad; Google Ads: keyword → hizmet), with the brand's city — the source of the
 *   hizmet ortalaması (median cost per result per service × city × type), the karne and Kazananlar;
 * - ad_campaign_stats: every Meta campaign's 30-day row (type, results, cost, services, alerts) for Meta masası.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_service_stats')) {
            Schema::create('ad_service_stats', function (Blueprint $table): void {
                $table->id();
                $table->string('channel', 16); // meta | google_ads
                $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->unsignedBigInteger('brand_offering_id');
                $table->unsignedBigInteger('service_id')->nullable(); // service catalog item, shared across brands
                $table->unsignedBigInteger('sector_id')->nullable();
                $table->string('city', 80)->default('');
                $table->string('result_type', 16); // leads | messages | purchases | conversions
                $table->decimal('spend', 14, 2)->default(0);
                $table->decimal('results', 12, 2)->default(0);
                $table->date('period_end');
                $table->timestamps();
                $table->index(['channel', 'service_id', 'result_type'], 'ad_service_stats_service_idx');
                $table->index(['brand_id', 'channel'], 'ad_service_stats_brand_idx');
            });
        }
        if (! Schema::hasTable('ad_campaign_stats')) {
            Schema::create('ad_campaign_stats', function (Blueprint $table): void {
                $table->id();
                $table->string('channel', 16);
                $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('campaign_id', 64);
                $table->string('name', 300);
                $table->string('status', 16);
                $table->string('result_type', 16);
                $table->decimal('spend', 14, 2)->default(0);
                $table->decimal('results', 12, 2)->default(0);
                $table->decimal('cpr', 14, 2)->nullable();
                $table->decimal('prev_cpr', 14, 2)->nullable();
                $table->string('service_state', 16);
                $table->json('services')->nullable(); // [{id, name, service_id, status}]
                $table->json('alerts')->nullable(); // [{key, label, tone}]
                $table->string('currency', 8)->nullable();
                $table->date('period_end');
                $table->timestamps();
                $table->unique(['channel', 'digital_asset_id', 'campaign_id'], 'ad_campaign_stats_unique');
                $table->index(['brand_id', 'channel'], 'ad_campaign_stats_brand_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_stats');
        Schema::dropIfExists('ad_service_stats');
    }
};
