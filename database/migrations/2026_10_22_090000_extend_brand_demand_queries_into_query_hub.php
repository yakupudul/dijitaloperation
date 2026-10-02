<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Query hub (Faz 1–2): the brand demand table becomes the single per-brand store of every query the brand sees —
 * its own accounts (Search Console per website, Google Ads search terms, Business Profile keywords), its query
 * portfolio (library queries, stored DataForSEO volume), competitor and area SERP keywords. Each row carries the
 * joined service (with the method and confidence of the assignment), sector, intent, relevance (belirsiz /
 * alakasız) and a 28-day trend. Site-specific Search Console metrics live in brand_demand_query_assets.
 * Queries are single-type: the older per-query area columns (location_status, locations, brand_service_area_id) are
 * cleared and no longer written; location-based content comes from the brand's service areas in the content phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_demand_queries', function (Blueprint $table): void {
            $table->foreignId('search_query_library_item_id')->nullable()->constrained('search_query_library_items')->nullOnDelete();
            $table->foreignId('brand_query_portfolio_item_id')->nullable()->constrained('brand_query_portfolio_items')->nullOnDelete();
            $table->unsignedSmallInteger('source_mask')->default(0);
            $table->decimal('gsc_position', 6, 2)->nullable();
            $table->unsignedBigInteger('search_volume')->nullable();
            $table->unsignedSmallInteger('serp_rank')->nullable();
            $table->unsignedSmallInteger('competitor_count')->default(0);
            $table->unsignedBigInteger('recent_impressions')->default(0);
            $table->unsignedBigInteger('previous_impressions')->default(0);
            $table->unsignedBigInteger('recent_clicks')->default(0);
            $table->unsignedBigInteger('previous_clicks')->default(0);
            $table->date('first_observed_on')->nullable();
            $table->date('last_observed_on')->nullable();
            $table->string('sector', 120)->nullable();
            $table->string('intent', 24)->nullable();
            $table->string('relevance', 16)->default('unclear'); // relevant | unclear | irrelevant
            $table->string('relevance_source', 16)->default('auto'); // auto | operator
            $table->string('assignment_method', 16)->nullable(); // operator | rule | portfolio | library | embedding
            $table->decimal('assignment_confidence', 4, 2)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['brand_id', 'relevance', 'value_score'], 'brand_demand_queries_relevance_idx');
            $table->index(['brand_id', 'is_branded'], 'brand_demand_queries_branded_idx');
        });

        Schema::create('brand_demand_query_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_demand_query_id')->constrained('brand_demand_queries')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->unsignedBigInteger('gsc_clicks')->default(0);
            $table->unsignedBigInteger('gsc_impressions')->default(0);
            $table->decimal('gsc_position', 6, 2)->nullable();
            $table->unsignedBigInteger('recent_impressions')->default(0);
            $table->unsignedBigInteger('previous_impressions')->default(0);
            $table->date('first_observed_on')->nullable();
            $table->date('last_observed_on')->nullable();
            $table->timestampTz('built_at')->nullable();
            $table->timestampsTz();

            $table->unique(['brand_demand_query_id', 'digital_asset_id'], 'brand_demand_query_assets_uq');
            $table->index(['digital_asset_id', 'gsc_impressions'], 'brand_demand_query_assets_asset_idx');
        });

        $bits = ['search_console' => 1, 'google_ads' => 2, 'google_business_profile' => 4];
        DB::table('brand_demand_queries')->orderBy('id')->chunkById(500, function ($rows) use ($bits): void {
            foreach ($rows as $row) {
                $mask = 0;
                foreach ((array) json_decode((string) $row->sources, true) as $source) {
                    $mask |= $bits[$source] ?? 0;
                }
                DB::table('brand_demand_queries')->where('id', $row->id)->update([
                    'source_mask' => $mask,
                    'relevance' => $row->brand_offering_id !== null || (bool) $row->is_branded ? 'relevant' : 'unclear',
                    'assignment_method' => $row->brand_offering_id === null ? null : ($row->assignment_source === 'operator' ? 'operator' : 'rule'),
                    // Queries are single-type: no per-query service area / region classification any more.
                    'location_status' => 'none', 'locations' => null, 'brand_service_area_id' => null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_demand_query_assets');
        if (Schema::hasTable('brand_demand_queries')) {
            Schema::table('brand_demand_queries', function (Blueprint $table): void {
                $table->dropIndex('brand_demand_queries_relevance_idx');
                $table->dropIndex('brand_demand_queries_branded_idx');
                $table->dropConstrainedForeignId('search_query_library_item_id');
                $table->dropConstrainedForeignId('brand_query_portfolio_item_id');
                $table->dropConstrainedForeignId('reviewed_by');
                $table->dropColumn([
                    'source_mask', 'gsc_position', 'search_volume', 'serp_rank', 'competitor_count', 'recent_impressions',
                    'previous_impressions', 'recent_clicks', 'previous_clicks', 'first_observed_on', 'last_observed_on', 'sector',
                    'intent', 'relevance', 'relevance_source', 'assignment_method', 'assignment_confidence', 'reviewed_at',
                ]);
            });
        }
    }
};
