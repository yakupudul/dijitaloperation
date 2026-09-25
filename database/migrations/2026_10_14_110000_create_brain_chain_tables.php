<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Brain, phase 3: the service chain is stored, not guessed at run time — which topic cluster each Google Ads
 * ad group serves, which service and message angle each Meta ad is about — plus one table for the Brain's
 * recommendations to a brand (every channel, with evidence and whether the method behind it is proven).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_ad_group_clusters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $t->foreignId('brand_id')->nullable()->index();
            $t->string('campaign_id', 64)->nullable();
            $t->string('ad_group_id', 64);
            $t->string('ad_group_name', 500)->nullable();
            $t->foreignId('service_id')->nullable()->index();
            $t->foreignId('cluster_id')->nullable()->index();
            $t->decimal('cluster_share', 5, 4)->nullable();
            $t->json('cluster_mix')->nullable();
            $t->text('final_url')->nullable();
            $t->text('target_url')->nullable();
            $t->boolean('url_matches')->nullable();
            $t->decimal('quality_score', 4, 2)->nullable();
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->decimal('conversions', 14, 2)->default(0);
            $t->decimal('cost', 16, 2)->default(0);
            $t->timestamp('computed_at');
            $t->timestamps();
            $t->unique(['digital_asset_id', 'ad_group_id']);
        });

        Schema::create('brain_meta_ads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $t->foreignId('brand_id')->nullable()->index();
            $t->string('ad_id', 64);
            $t->string('ad_name', 500)->nullable();
            $t->string('creative_id', 64)->nullable();
            $t->foreignId('service_id')->nullable()->index();
            $t->string('angle', 32)->nullable();
            $t->string('format', 16)->nullable();
            $t->string('source', 16)->nullable();
            $t->decimal('confidence', 5, 4)->nullable();
            $t->decimal('spend', 16, 2)->default(0);
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->decimal('results', 14, 2)->default(0);
            $t->timestamp('computed_at');
            $t->timestamps();
            $t->unique(['digital_asset_id', 'ad_id']);
        });

        Schema::create('brain_recommendations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('brand_id')->nullable()->index();
            $t->foreignId('digital_asset_id')->nullable()->index();
            $t->foreignId('service_id')->nullable()->index();
            $t->foreignId('cluster_id')->nullable();
            $t->string('source', 32);
            $t->string('channel', 32);
            $t->string('type', 64);
            $t->string('title', 500);
            $t->text('detail')->nullable();
            $t->json('evidence')->nullable();
            $t->decimal('impact', 16, 2)->nullable();
            $t->string('basis', 16)->default('rule');
            $t->foreignId('method_id')->nullable()->index();
            $t->string('fingerprint', 64)->index();
            $t->string('status', 16)->default('open');
            $t->json('baseline')->nullable();
            $t->json('outcome')->nullable();
            $t->timestamp('measured_at')->nullable();
            $t->foreignId('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['source', 'digital_asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_recommendations');
        Schema::dropIfExists('brain_meta_ads');
        Schema::dropIfExists('brain_ad_group_clusters');
    }
};
