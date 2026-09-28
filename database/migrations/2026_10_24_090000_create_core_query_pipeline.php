<?php

use App\Services\SeoTasks\SeoText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2 query pipeline: one query truth.
 *  - search_query_library_items is the CORE query (location / brand / product free) with aggregated metrics;
 *  - query_variants archives every raw provider query per source account (GSC property, Ads account, Business
 *    Profile location) with its window metrics, strip flags and the core it maps to (or its separate list);
 *  - asset_sectors holds the sector of every discovered account / website (AI or manual; manual wins);
 *  - sector_product_brands is the editable product / manufacturer brand list per sector;
 *  - library_query_clusters gets the page-type decision with its SERP evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_variants', function (Blueprint $t): void {
            $t->id();
            $t->string('source', 32);
            $t->unsignedBigInteger('external_resource_id')->nullable()->index();
            $t->unsignedBigInteger('digital_asset_id')->nullable()->index();
            $t->string('source_key', 64);
            $t->char('text_hash', 64);
            $t->text('raw_text');
            $t->foreignId('search_query_library_item_id')->nullable()->constrained('search_query_library_items')->nullOnDelete();
            $t->string('kind', 16)->default('core');
            $t->boolean('had_location')->default(false);
            $t->boolean('had_own_brand')->default(false);
            $t->boolean('had_competitor_brand')->default(false);
            $t->boolean('had_product_brand')->default(false);
            $t->json('removed')->nullable();
            $t->bigInteger('impressions')->default(0);
            $t->bigInteger('clicks')->default(0);
            $t->decimal('cost', 20, 6)->default(0);
            $t->decimal('conversions', 20, 4)->default(0);
            $t->decimal('position_weighted', 20, 4)->default(0);
            $t->bigInteger('position_impressions')->default(0);
            $t->bigInteger('recent_impressions')->default(0);
            $t->bigInteger('previous_impressions')->default(0);
            $t->bigInteger('recent_clicks')->default(0);
            $t->bigInteger('previous_clicks')->default(0);
            $t->date('first_seen_on')->nullable();
            $t->date('last_seen_on')->nullable();
            $t->char('context_hash', 64)->nullable();
            $t->timestampTz('ingested_at')->nullable();
            $t->timestampsTz();
            $t->unique(['source_key', 'text_hash'], 'query_variants_source_text_uq');
            $t->index(['search_query_library_item_id', 'kind'], 'query_variants_core_kind_idx');
            $t->index(['kind', 'source'], 'query_variants_kind_idx');
        });

        Schema::create('query_ingest_states', function (Blueprint $t): void {
            $t->id();
            $t->string('source_key', 64)->unique();
            $t->string('source', 32);
            $t->unsignedBigInteger('external_resource_id')->nullable();
            $t->unsignedBigInteger('digital_asset_id')->nullable();
            $t->char('facts_fingerprint', 64)->nullable();
            $t->char('context_hash', 64)->nullable();
            $t->unsignedInteger('variants')->default(0);
            $t->text('error')->nullable();
            $t->timestampTz('ingested_at')->nullable();
            $t->timestampsTz();
        });

        Schema::create('asset_sectors', function (Blueprint $t): void {
            $t->id();
            $t->string('subject_type', 16);
            $t->unsignedBigInteger('subject_id');
            $t->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $t->decimal('confidence', 4, 3)->nullable();
            $t->string('method', 16)->default('none');
            $t->string('reason', 500)->nullable();
            $t->char('signals_hash', 64)->nullable();
            $t->timestampTz('assigned_at')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['subject_type', 'subject_id'], 'asset_sectors_subject_uq');
        });

        Schema::create('sector_product_brands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $t->string('label');
            $t->string('normalized_key');
            $t->timestampsTz();
            $t->unique(['service_category_id', 'normalized_key'], 'sector_product_brand_uq');
        });

        Schema::table('search_query_library_items', function (Blueprint $t): void {
            $t->char('core_key', 64)->nullable()->index();
            $t->bigInteger('gsc_impressions')->default(0);
            $t->bigInteger('gsc_clicks')->default(0);
            $t->bigInteger('ads_impressions')->default(0);
            $t->bigInteger('ads_clicks')->default(0);
            $t->decimal('ads_cost', 20, 6)->default(0);
            $t->decimal('ads_conversions', 20, 4)->default(0);
            $t->bigInteger('gbp_impressions')->default(0);
            $t->unsignedInteger('variant_count')->default(0);
            $t->timestampTz('metrics_at')->nullable();
        });

        Schema::table('search_query_library_sectors', function (Blueprint $t): void {
            $t->string('match_status', 16)->default('pending');
            $t->string('match_method', 16)->nullable();
            $t->timestampTz('matched_at')->nullable();
            $t->timestampTz('ai_checked_at')->nullable();
            $t->index(['match_status', 'service_category_id'], 'query_sector_match_idx');
        });

        Schema::table('library_query_clusters', function (Blueprint $t): void {
            $t->string('page_decision', 16)->nullable();
            $t->string('decision_source', 16)->nullable();
            $t->json('serp_evidence')->nullable();
            $t->text('research_query')->nullable();
            $t->char('research_fingerprint', 64)->nullable();
            $t->timestampTz('researched_at')->nullable();
        });

        // Backfill: core keys, and sector links that already have a service there count as matched.
        DB::table('search_query_library_items')->orderBy('id')->chunkById(500, function ($items): void {
            foreach ($items as $item) {
                DB::table('search_query_library_items')->where('id', $item->id)
                    ->update(['core_key' => hash('sha256', SeoText::fold((string) $item->canonical_text))]);
            }
        });
        $matched = DB::table('search_query_library_item_service as s')
            ->join('service_catalog_items as c', 'c.id', '=', 's.service_catalog_item_id')
            ->join('service_categories as cat', 'cat.code', '=', 'c.sector')
            ->select('s.search_query_library_item_id', 'cat.id as category_id')->get();
        foreach ($matched as $row) {
            DB::table('search_query_library_sectors')->where('search_query_library_item_id', $row->search_query_library_item_id)
                ->where('service_category_id', $row->category_id)
                ->update(['match_status' => 'matched', 'match_method' => 'existing', 'matched_at' => now()]);
        }
        // Existing account mappings (the retired per-account sector setting) become manual asset sectors.
        if (Schema::hasColumn('resource_automations', 'sector')) {
            $categories = DB::table('service_categories')->pluck('id', 'code');
            foreach (DB::table('resource_automations')->whereNotNull('sector')->get(['external_resource_id', 'sector', 'updated_by']) as $row) {
                if (! isset($categories[$row->sector])) {
                    continue;
                }
                DB::table('asset_sectors')->insertOrIgnore([
                    'subject_type' => 'resource', 'subject_id' => $row->external_resource_id,
                    'service_category_id' => $categories[$row->sector], 'confidence' => 1, 'method' => 'manual',
                    'reason' => 'Önceki hesap eşlemesi', 'assigned_at' => now(), 'assigned_by' => $row->updated_by,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        // Default product / manufacturer brands (editable per sector).
        $defaults = (array) config('moxdop-queries.product_brands', []);
        foreach (DB::table('service_categories')->whereIn('code', array_keys($defaults))->get(['id', 'code']) as $category) {
            foreach ($defaults[$category->code] as $label) {
                DB::table('sector_product_brands')->insertOrIgnore([
                    'service_category_id' => $category->id, 'label' => $label, 'normalized_key' => SeoText::fold($label),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('library_query_clusters', function (Blueprint $t): void {
            $t->dropColumn(['page_decision', 'decision_source', 'serp_evidence', 'research_query', 'research_fingerprint', 'researched_at']);
        });
        Schema::table('search_query_library_sectors', function (Blueprint $t): void {
            $t->dropIndex('query_sector_match_idx');
            $t->dropColumn(['match_status', 'match_method', 'matched_at', 'ai_checked_at']);
        });
        Schema::table('search_query_library_items', function (Blueprint $t): void {
            $t->dropIndex(['core_key']);
            $t->dropColumn(['core_key', 'gsc_impressions', 'gsc_clicks', 'ads_impressions', 'ads_clicks', 'ads_cost', 'ads_conversions', 'gbp_impressions', 'variant_count', 'metrics_at']);
        });
        Schema::dropIfExists('sector_product_brands');
        Schema::dropIfExists('asset_sectors');
        Schema::dropIfExists('query_ingest_states');
        Schema::dropIfExists('query_variants');
    }
};
