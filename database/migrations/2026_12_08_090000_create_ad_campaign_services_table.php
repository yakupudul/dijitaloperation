<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kampanya → hizmet: the brand services an ad campaign serves (Meta now, Google Ads later). A row is a system
 * suggestion (source page | text | name | ai) until the operator confirms it; the operator's own rows (confirmed,
 * removed, or "hizmet dışı" = excluded with no service) are never changed by a later automatic pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_campaign_services')) {
            return;
        }
        Schema::create('ad_campaign_services', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->unsignedBigInteger('digital_asset_id');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('campaign_id', 64);
            $table->unsignedBigInteger('brand_offering_id')->nullable();
            $table->string('status', 16);
            $table->string('source', 16);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['digital_asset_id', 'campaign_id'], 'ad_campaign_services_campaign_idx');
            $table->index(['brand_offering_id'], 'ad_campaign_services_offering_idx');
            $table->index(['channel', 'brand_id'], 'ad_campaign_services_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_services');
    }
};
