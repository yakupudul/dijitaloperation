<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Validates the Meta Ads historical store migration: columns, unique keys, and
 * foreign keys anchored on core_integrations / core_external_resources (not
 * digital_assets).
 */
class MetaHistoricalStoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_ads_entities_table_shape(): void
    {
        $this->assertTrue(Schema::hasTable('meta_ads_entities'));
        $this->assertTrue(Schema::hasColumns('meta_ads_entities', [
            'id', 'core_integration_id', 'core_external_resource_id', 'entity_type',
            'provider_external_id', 'parent_provider_external_id', 'name', 'status',
            'objective', 'optimization_goal', 'destination_type', 'creative_provider_id',
            'currency', 'metadata', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at',
        ]));

        $indexes = Schema::getIndexes('meta_ads_entities');
        $unique = collect($indexes)->first(fn (array $i): bool => $i['columns'] === ['core_external_resource_id', 'entity_type', 'provider_external_id']);
        $this->assertNotNull($unique);
        $this->assertTrue($unique['unique']);

        $foreignKeys = Schema::getForeignKeys('meta_ads_entities');
        $this->assertNotNull(collect($foreignKeys)->first(
            fn (array $fk): bool => $fk['columns'] === ['core_integration_id'] && $fk['foreign_table'] === 'core_integrations'
        ));
        $this->assertNotNull(collect($foreignKeys)->first(
            fn (array $fk): bool => $fk['columns'] === ['core_external_resource_id'] && $fk['foreign_table'] === 'core_external_resources'
        ));
    }

    public function test_meta_ads_daily_facts_table_shape(): void
    {
        $this->assertTrue(Schema::hasTable('meta_ads_daily_facts'));
        $this->assertTrue(Schema::hasColumns('meta_ads_daily_facts', [
            'id', 'core_integration_id', 'core_external_resource_id', 'entity_type',
            'provider_external_id', 'parent_provider_external_id', 'date', 'spend',
            'impressions', 'clicks', 'link_clicks', 'outbound_clicks', 'reach', 'frequency',
            'cpc', 'cpm', 'ctr', 'link_ctr', 'currency', 'attribution_setting', 'provenance',
        ]));

        $indexes = Schema::getIndexes('meta_ads_daily_facts');
        $unique = collect($indexes)->first(
            fn (array $i): bool => $i['columns'] === ['core_external_resource_id', 'entity_type', 'provider_external_id', 'date']
        );
        $this->assertNotNull($unique);
        $this->assertTrue($unique['unique']);
    }

    public function test_meta_ads_daily_actions_table_shape_and_unique_key(): void
    {
        $this->assertTrue(Schema::hasTable('meta_ads_daily_actions'));
        $this->assertTrue(Schema::hasColumns('meta_ads_daily_actions', [
            'id', 'core_integration_id', 'core_external_resource_id', 'entity_type',
            'provider_external_id', 'date', 'raw_action_type', 'normalized_family',
            'value', 'action_value', 'attribution_window', 'provenance',
        ]));

        $columns = collect(Schema::getColumns('meta_ads_daily_actions'));
        $attributionWindow = $columns->firstWhere('name', 'attribution_window');
        $this->assertNotNull($attributionWindow);
        $this->assertFalse($attributionWindow['nullable']);

        $indexes = Schema::getIndexes('meta_ads_daily_actions');
        $unique = collect($indexes)->first(
            fn (array $i): bool => $i['columns'] === [
                'core_external_resource_id', 'entity_type', 'provider_external_id', 'date', 'raw_action_type', 'attribution_window',
            ]
        );
        $this->assertNotNull($unique);
        $this->assertTrue($unique['unique']);
    }

    public function test_meta_ads_period_aggregates_table_shape(): void
    {
        $this->assertTrue(Schema::hasTable('meta_ads_period_aggregates'));
        $this->assertTrue(Schema::hasColumns('meta_ads_period_aggregates', [
            'id', 'core_integration_id', 'core_external_resource_id', 'entity_type',
            'provider_external_id', 'date_from', 'date_to', 'attribution_context',
            'metric_key', 'metric_value', 'status', 'provenance', 'run_id', 'fetched_at',
        ]));

        $indexes = Schema::getIndexes('meta_ads_period_aggregates');
        $unique = collect($indexes)->first(
            fn (array $i): bool => $i['columns'] === [
                'core_external_resource_id', 'entity_type', 'provider_external_id', 'date_from', 'date_to', 'attribution_context', 'metric_key',
            ]
        );
        $this->assertNotNull($unique);
        $this->assertTrue($unique['unique']);
    }

    public function test_meta_ads_history_coverage_table_shape(): void
    {
        $this->assertTrue(Schema::hasTable('meta_ads_history_coverage'));
        $this->assertTrue(Schema::hasColumns('meta_ads_history_coverage', [
            'id', 'core_integration_id', 'core_external_resource_id', 'data_layer',
            'granularity', 'start_date', 'end_date', 'status', 'last_successful_sync_at',
            'earliest_provider_date', 'latest_provider_date', 'gaps', 'import_run_id', 'summary',
        ]));

        $indexes = Schema::getIndexes('meta_ads_history_coverage');
        $unique = collect($indexes)->first(
            fn (array $i): bool => $i['columns'] === ['core_external_resource_id', 'data_layer', 'granularity']
        );
        $this->assertNotNull($unique);
        $this->assertTrue($unique['unique']);
    }
}
