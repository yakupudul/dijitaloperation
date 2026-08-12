<?php

namespace Tests\Feature;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\MetaAds\History\MetaHistoricalQueryService;
use MoxDop\MetaAds\History\MetaHistoricalUpserter;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Models\MetaAdsPeriodAggregate;
use Tests\TestCase;

/**
 * Validates the critical aggregation rules: missing != zero, never sum reach, never
 * average frequency, and the CTR/CPC/CPM recomputation formulas.
 */
class MetaHistoricalQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private MetaHistoricalQueryService $query;

    private MetaHistoricalUpserter $upserter;

    private CoreIntegration $integration;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = app(MetaHistoricalQueryService::class);
        $this->upserter = app(MetaHistoricalUpserter::class);
        $this->integration = CoreIntegration::factory()->meta()->create();
        $this->resource = CoreExternalResource::factory()->metaAds()->create([
            'integration_id' => $this->integration->id,
        ]);
    }

    private function fact(string $date, array $overrides = []): void
    {
        $this->upserter->upsertDailyFact([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date' => $date,
            ...$overrides,
        ]);
    }

    public function test_account_facts_sums_additive_metrics_and_recomputes_rates(): void
    {
        $this->fact('2026-01-01', ['spend' => 100.0, 'impressions' => 1000, 'clicks' => 50, 'link_clicks' => 30]);
        $this->fact('2026-01-02', ['spend' => 200.0, 'impressions' => 2000, 'clicks' => 100, 'link_clicks' => 60]);

        $result = $this->query->accountFacts($this->resource, '2026-01-01', '2026-01-02');

        $this->assertSame(300.0, $result['spend']);
        $this->assertSame(3000, $result['impressions']);
        $this->assertSame(150, $result['clicks']);
        $this->assertSame(90, $result['link_clicks']);
        // CTR percentage points = sum(clicks) / sum(impressions) * 100 = 150 / 3000 * 100 = 5.0
        $this->assertSame(5.0, $result['ctr']);
        // Link CTR percentage points = sum(link_clicks) / sum(impressions) * 100 = 90 / 3000 * 100 = 3.0
        $this->assertSame(3.0, $result['link_ctr']);
        // CPC = sum(spend) / sum(clicks) = 300 / 150 = 2.0
        $this->assertSame(2.0, $result['cpc']);
        // CPM = sum(spend) / sum(impressions) * 1000 = 300 / 3000 * 1000 = 100.0
        $this->assertSame(100.0, $result['cpm']);
    }

    public function test_account_facts_missing_metric_is_null_not_zero_when_no_rows(): void
    {
        $result = $this->query->accountFacts($this->resource, '2026-01-01', '2026-01-31');

        $this->assertNull($result['spend']);
        $this->assertNull($result['impressions']);
        $this->assertNull($result['clicks']);
        $this->assertNull($result['ctr']);
        $this->assertNull($result['cpc']);
        $this->assertNull($result['cpm']);
    }

    public function test_ctr_is_null_when_impressions_denominator_missing(): void
    {
        $this->fact('2026-01-01', ['clicks' => 10]); // no impressions column set

        $result = $this->query->accountFacts($this->resource, '2026-01-01', '2026-01-01');

        $this->assertSame(10, $result['clicks']);
        $this->assertNull($result['impressions']);
        $this->assertNull($result['ctr'], 'CTR must be null (not 0) when impressions are unknown.');
    }

    public function test_cpc_is_null_when_clicks_sum_to_zero(): void
    {
        $this->fact('2026-01-01', ['spend' => 50.0, 'clicks' => 0]);

        $result = $this->query->accountFacts($this->resource, '2026-01-01', '2026-01-01');

        $this->assertSame(50.0, $result['spend']);
        $this->assertSame(0, $result['clicks']);
        $this->assertNull($result['cpc'], 'CPC must be null (not division-by-zero or 0) when clicks are zero.');
    }

    public function test_account_facts_never_expose_reach_or_frequency(): void
    {
        $this->fact('2026-01-01', ['reach' => 900, 'frequency' => 1.8]);
        $this->fact('2026-01-02', ['reach' => 950, 'frequency' => 1.9]);

        $result = $this->query->accountFacts($this->resource, '2026-01-01', '2026-01-02');

        $this->assertArrayNotHasKey('reach', $result, 'Reach must never be surfaced from a summed range aggregate.');
        $this->assertArrayNotHasKey('frequency', $result, 'Frequency must never be surfaced from an averaged range aggregate.');
    }

    public function test_daily_series_exposes_raw_per_day_reach_and_frequency(): void
    {
        $this->fact('2026-01-01', ['reach' => 900, 'frequency' => 1.8]);
        $this->fact('2026-01-02', ['reach' => 950, 'frequency' => 1.9]);

        $series = $this->query->dailySeries($this->resource, 'account', $this->resource->external_id, '2026-01-01', '2026-01-02');

        $this->assertCount(2, $series);
        $this->assertSame('2026-01-01', $series[0]['date']);
        $this->assertSame(900, $series[0]['reach']);
        $this->assertSame(1.8, $series[0]['frequency']);
        $this->assertSame(950, $series[1]['reach']);
        $this->assertSame(1.9, $series[1]['frequency']);
    }

    public function test_daily_series_omits_days_with_no_stored_fact_row(): void
    {
        $this->fact('2026-01-01', ['spend' => 10.0]);
        // 2026-01-02 intentionally has no row (not collected, not zero).

        $series = $this->query->dailySeries($this->resource, 'account', $this->resource->external_id, '2026-01-01', '2026-01-02', ['spend']);

        $this->assertCount(1, $series, 'Missing days must not be fabricated as zero-filled points.');
        $this->assertSame('2026-01-01', $series[0]['date']);
    }

    public function test_entity_facts_groups_by_provider_id_and_filters_by_parent(): void
    {
        $this->upserter->upsertDailyFact([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'campaign',
            'provider_external_id' => 'c1',
            'parent_provider_external_id' => $this->resource->external_id,
            'date' => '2026-01-01',
            'spend' => 40.0,
            'impressions' => 400,
        ]);
        $this->upserter->upsertDailyFact([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'campaign',
            'provider_external_id' => 'c2',
            'parent_provider_external_id' => $this->resource->external_id,
            'date' => '2026-01-01',
            'spend' => 60.0,
            'impressions' => 600,
        ]);

        $rows = $this->query->entityFacts($this->resource, 'campaign', '2026-01-01', '2026-01-01');
        $this->assertCount(2, $rows);

        $filtered = $this->query->entityFacts($this->resource, 'campaign', '2026-01-01', '2026-01-01', [
            'provider_external_ids' => ['c1'],
        ]);
        $this->assertCount(1, $filtered);
        $this->assertSame('c1', $filtered[0]['provider_external_id']);
        $this->assertSame(40.0, $filtered[0]['spend']);
    }

    public function test_resolve_reach_frequency_is_pending_when_no_period_aggregate_cached(): void
    {
        $result = $this->query->resolveReachFrequency($this->resource, 'account', $this->resource->external_id, '2026-01-01', '2026-01-07');

        $this->assertSame('pending', $result['status']);
        $this->assertNull($result['reach']);
        $this->assertNull($result['frequency']);
    }

    public function test_resolve_reach_frequency_returns_exact_cached_values_when_ready(): void
    {
        $this->upserter->upsertPeriodAggregate([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-07',
            'metric_key' => MetaAdsPeriodAggregate::METRIC_REACH,
            'metric_value' => 12345.0,
            'status' => MetaAdsPeriodAggregate::STATUS_READY,
        ]);
        $this->upserter->upsertPeriodAggregate([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-07',
            'metric_key' => MetaAdsPeriodAggregate::METRIC_FREQUENCY,
            'metric_value' => 2.345,
            'status' => MetaAdsPeriodAggregate::STATUS_READY,
        ]);

        $result = $this->query->resolveReachFrequency($this->resource, 'account', $this->resource->external_id, '2026-01-01', '2026-01-07');

        $this->assertSame('ready', $result['status']);
        $this->assertSame(12345, $result['reach']);
        $this->assertSame(2.345, $result['frequency']);
    }

    public function test_resolve_reach_frequency_is_unavailable_when_provider_could_not_deliver(): void
    {
        $this->upserter->upsertPeriodAggregate([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-07',
            'metric_key' => MetaAdsPeriodAggregate::METRIC_REACH,
            'metric_value' => null,
            'status' => MetaAdsPeriodAggregate::STATUS_FAILED,
        ]);

        $result = $this->query->resolveReachFrequency($this->resource, 'account', $this->resource->external_id, '2026-01-01', '2026-01-07');

        $this->assertSame('unavailable', $result['status']);
    }

    public function test_result_mix_sums_same_action_type_across_days_without_blending_types(): void
    {
        $this->upserter->upsertDailyActions([
            [
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => 'account',
                'provider_external_id' => $this->resource->external_id,
                'date' => '2026-01-01',
                'raw_action_type' => 'lead',
                'normalized_family' => 'lead',
                'value' => 3.0,
            ],
            [
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => 'account',
                'provider_external_id' => $this->resource->external_id,
                'date' => '2026-01-02',
                'raw_action_type' => 'lead',
                'normalized_family' => 'lead',
                'value' => 5.0,
            ],
            [
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => 'account',
                'provider_external_id' => $this->resource->external_id,
                'date' => '2026-01-01',
                'raw_action_type' => 'purchase',
                'normalized_family' => 'purchase',
                'value' => 1.0,
            ],
        ]);

        $mix = $this->query->resultMix($this->resource, 'account', $this->resource->external_id, '2026-01-01', '2026-01-02');

        $this->assertFalse($mix['blind_action_sum']);
        $byType = collect($mix['raw_items'])->keyBy('raw_action_type');
        $this->assertSame(8.0, $byType['lead']['count']);
        $this->assertSame(1.0, $byType['purchase']['count']);
    }

    public function test_coverage_for_resource_and_is_range_covered(): void
    {
        $this->upserter->updateCoverage($this->integration, $this->resource, MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, [
            'status' => MetaAdsHistoryCoverage::STATUS_COMPLETE,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);

        $coverage = $this->query->coverageForResource($this->resource);
        $this->assertSame('complete', $coverage[MetaAdsHistoryCoverage::LAYER_DAILY_FACTS]['status']);

        $this->assertSame('complete', $this->query->isRangeCovered($this->resource, '2026-01-05', '2026-01-10'));
        $this->assertSame('not_imported', $this->query->isRangeCovered($this->resource, '2026-02-01', '2026-02-10'));
        $this->assertSame('not_imported', $this->query->isRangeCovered($this->resource, '2026-01-05', '2026-01-10', MetaAdsHistoryCoverage::LAYER_ENTITIES));
    }

    public function test_is_range_covered_serves_local_data_while_reimport_is_in_flight(): void
    {
        $this->upserter->updateCoverage($this->integration, $this->resource, MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, [
            'status' => MetaAdsHistoryCoverage::STATUS_IMPORTING,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);

        // Dates already cover the operator period — return partial so Overview queries local facts.
        $this->assertSame('partial', $this->query->isRangeCovered($this->resource, '2026-01-05', '2026-01-10'));
        // Outside the imported bounds while importing → still importing (gap may fill).
        $this->assertSame('importing', $this->query->isRangeCovered($this->resource, '2026-02-01', '2026-02-10'));
    }

    public function test_is_range_covered_outside_provider_history_window(): void
    {
        $status = $this->query->isRangeCovered($this->resource, '2000-01-01', '2000-01-31');

        $this->assertSame('outside_provider', $status);
    }
}
