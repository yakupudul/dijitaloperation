<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2: every discovered Meta ad account is collected (bound or not). Central rows carry no Digital Asset, so
 * the V1 Meta tables get the provider-resource natural key (config moxdop-meta-ads-central.natural_key_overrides).
 * Rows of the same account written for two assets are collapsed to the newest one first. Compact tables (views over
 * a fact table) keep their stored layout key and are skipped.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const array KEYS = [
        'meta_ad_account_snapshot' => ['external_resource_id', 'account_id'],
        'meta_campaign_snapshot' => ['external_resource_id', 'account_id', 'campaign_id'],
        'meta_adset_snapshot' => ['external_resource_id', 'account_id', 'adset_id'],
        'meta_creative_snapshot' => ['external_resource_id', 'account_id', 'creative_id'],
        'meta_campaign_daily' => ['external_resource_id', 'account_id', 'reporting_date', 'campaign_id'],
        'meta_adset_daily' => ['external_resource_id', 'account_id', 'reporting_date', 'adset_id'],
        'meta_ad_daily' => ['external_resource_id', 'account_id', 'reporting_date', 'ad_id'],
        'meta_typed_action_daily' => ['external_resource_id', 'account_id', 'reporting_date', 'entity_level', 'entity_id', 'action_type'],
    ];

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        foreach (self::KEYS as $table => $columns) {
            if (! Schema::hasTable($table) || ($pgsql && $this->isView($table))) {
                continue;
            }
            $key = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $columns));
            DB::statement(sprintf('DELETE FROM "%1$s" WHERE id IN (SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY %2$s ORDER BY last_collected_at DESC, id DESC) AS rn FROM "%1$s" WHERE external_resource_id IS NOT NULL) ranked WHERE rn > 1)', $table, $key));
            DB::statement(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s" (%s)', $this->indexName($table), $table, $key));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::KEYS) as $table) {
            if (Schema::hasTable($table)) {
                DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', $this->indexName($table)));
            }
        }
    }

    private function isView(string $table): bool
    {
        return DB::selectOne('SELECT c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?', [$table])?->relkind === 'v';
    }

    private function indexName(string $table): string
    {
        return substr($table, 0, 43).'_resource_nk';
    }
};
