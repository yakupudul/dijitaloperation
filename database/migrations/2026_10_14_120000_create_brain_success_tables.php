<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Brain, phase 4: what each cluster page looks like (measured facts, plus a fixed AI checklist read on
 * click) and how well it performs, normalised so brands of different size and market can be compared within a
 * cohort (service × page type × market tier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_page_features', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $t->text('url');
            $t->string('url_key', 512);
            $t->string('content_hash', 64)->nullable();
            $t->json('features')->nullable();
            $t->json('ai_features')->nullable();
            $t->string('ai_hash', 64)->nullable();
            $t->timestamp('computed_at')->nullable();
            $t->timestamps();
            $t->unique(['digital_asset_id', 'url_key']);
        });

        Schema::create('brain_success_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->date('period');
            $t->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $t->foreignId('brand_id')->nullable()->index();
            $t->foreignId('service_id')->index();
            $t->foreignId('cluster_id')->index();
            $t->string('page_type', 16)->nullable();
            $t->string('market_tier', 16);
            $t->string('cohort_key', 120)->index();
            $t->unsignedInteger('cohort_size')->default(0);
            $t->text('url')->nullable();
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->decimal('position', 6, 2)->nullable();
            $t->decimal('ctr', 8, 5)->nullable();
            $t->decimal('ctr_index', 8, 3)->nullable();
            $t->decimal('ctr_shrunk', 8, 5)->nullable();
            $t->decimal('demand_share', 8, 5)->nullable();
            $t->unsignedBigInteger('sessions')->default(0);
            $t->decimal('engaged_rate', 6, 4)->nullable();
            $t->decimal('conversions', 12, 2)->default(0);
            $t->decimal('cvr_shrunk', 8, 5)->nullable();
            $t->decimal('score', 5, 1)->nullable();
            $t->json('components')->nullable();
            $t->timestamps();
            $t->unique(['period', 'digital_asset_id', 'cluster_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_success_snapshots');
        Schema::dropIfExists('brain_page_features');
    }
};
