<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $indexes = [
        'google_ads_account_snapshot' => ['external_resource_id', 'customer_id'],
        'google_ads_account_daily' => ['external_resource_id', 'customer_id', 'reporting_date'],
        'google_ads_campaign_snapshot' => ['external_resource_id', 'customer_id', 'campaign_id'],
        'google_ads_campaign_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id'],
        'google_ads_ad_group_snapshot' => ['external_resource_id', 'customer_id', 'ad_group_id'],
        'google_ads_ad_snapshot' => ['external_resource_id', 'customer_id', 'ad_id'],
        'google_ads_keyword_snapshot' => ['external_resource_id', 'customer_id', 'ad_group_id', 'criterion_id'],
        'google_ads_keyword_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'ad_group_id', 'criterion_id'],
        'google_ads_search_term_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'search_term'],
        'google_ads_landing_page_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'landing_page'],
        'google_ads_conversion_action_snapshot' => ['external_resource_id', 'customer_id', 'conversion_action_id'],
        'google_ads_conversion_action_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'conversion_action_id'],
        'google_ads_campaign_budget_snapshot' => ['external_resource_id', 'customer_id', 'budget_id'],
        'google_ads_asset_coverage_snapshot' => ['external_resource_id', 'customer_id', 'asset_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $duplicate = DB::table($table)
                ->select($columns)
                ->whereNotNull('external_resource_id')
                ->groupBy($columns)
                ->havingRaw('COUNT(*) > 1')
                ->limit(1)
                ->exists();

            if ($duplicate) {
                throw new \RuntimeException("Cannot enable Google Ads resource-first identity: duplicate provider rows exist in [{$table}]. Resolve duplicates before migration.");
            }

            $name = $this->indexName($table);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $quoted = implode(', ', array_map(fn (string $column): string => '"'.$column.'"', $columns));
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "'.$name.'" ON "'.$table.'" ('.$quoted.')');
            } else {
                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                    $blueprint->unique($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $name = $this->indexName($table);
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('DROP INDEX IF EXISTS "'.$name.'"');
            } else {
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropUnique($name);
                });
            }
        }
    }

    private function indexName(string $table): string
    {
        return substr($table, 0, 43).'_resource_nk';
    }
};
