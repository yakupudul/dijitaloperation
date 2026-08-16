<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 65 — measured index additions for pool reads + activity feeds.
 * No Customer partitions. Does not invent warehouse/sharding.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hotDailyTables = [
            'gsc_query_daily',
            'gsc_page_daily',
            'gsc_query_page_daily',
            'gsc_device_daily',
            'google_ads_search_term_daily',
            'google_ads_keyword_daily',
            'google_ads_campaign_daily',
            'meta_campaign_daily',
            'meta_ad_daily',
            'ga4_source_medium_daily',
            'ga4_landing_page_daily',
        ];

        foreach ($hotDailyTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $name = substr('idx_'.$table.'_asset_resource_date', 0, 63);
                if (! $this->hasIndex($table, $name)) {
                    $blueprint->index(
                        ['digital_asset_id', 'external_resource_id', 'reporting_date'],
                        $name,
                    );
                }
            });
        }

        if (Schema::hasTable('brand_context_activities')) {
            Schema::table('brand_context_activities', function (Blueprint $table): void {
                if (! $this->hasIndex('brand_context_activities', 'bca_brand_occurred_idx')) {
                    $table->index(['brand_id', 'occurred_at'], 'bca_brand_occurred_idx');
                }
                if (! $this->hasIndex('brand_context_activities', 'bca_customer_occurred_idx')) {
                    $table->index(['customer_id', 'occurred_at'], 'bca_customer_occurred_idx');
                }
            });
        }
    }

    public function down(): void
    {
        // Indexes are additive and safe to retain; intentional no-op for SQLite compatibility.
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $sm = Schema::getConnection()->getSchemaBuilder();
            if (method_exists($sm, 'getIndexes')) {
                foreach ($sm->getIndexes($table) as $index) {
                    if (($index['name'] ?? null) === $indexName) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
