<?php

namespace Tests\Feature;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\MetaAds\History\MetaHistoricalUpserter;
use MoxDop\MetaAds\Models\MetaAdsDailyAction;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsEntity;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Models\MetaAdsPeriodAggregate;
use Tests\TestCase;

class MetaHistoricalUpserterTest extends TestCase
{
    use RefreshDatabase;

    private MetaHistoricalUpserter $upserter;

    private CoreIntegration $integration;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->upserter = app(MetaHistoricalUpserter::class);
        $this->integration = CoreIntegration::factory()->meta()->create();
        $this->resource = CoreExternalResource::factory()->metaAds()->create([
            'integration_id' => $this->integration->id,
        ]);
    }

    public function test_upsert_entity_is_idempotent_and_tracks_first_and_last_seen(): void
    {
        $first = $this->upserter->upsertEntity($this->integration, $this->resource, [
            'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
            'provider_external_id' => '1001',
            'parent_provider_external_id' => $this->resource->external_id,
            'name' => 'Campaign A',
            'status' => 'ACTIVE',
            'objective' => 'OUTCOME_LEADS',
        ]);

        $this->assertSame(1, MetaAdsEntity::query()->count());
        $firstSeenAt = $first->first_seen_at;

        $this->travel(1)->hours();

        $second = $this->upserter->upsertEntity($this->integration, $this->resource, [
            'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
            'provider_external_id' => '1001',
            'name' => 'Campaign A (renamed)',
            'status' => 'PAUSED',
        ]);

        $this->assertSame(1, MetaAdsEntity::query()->count(), 'Upsert must never create duplicates.');
        $this->assertTrue($first->is($second));
        $this->assertSame('Campaign A (renamed)', $second->fresh()->name);
        $this->assertSame('PAUSED', $second->fresh()->status);
        $this->assertTrue($second->fresh()->first_seen_at->equalTo($firstSeenAt), 'first_seen_at must not change on update.');
        $this->assertTrue($second->fresh()->last_seen_at->greaterThan($firstSeenAt));
    }

    public function test_upsert_daily_fact_is_idempotent_by_natural_key(): void
    {
        $row = [
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date' => '2026-01-15',
            'spend' => 100.5,
            'impressions' => 1000,
            'reach' => 800,
        ];

        $this->upserter->upsertDailyFact($row);
        $this->upserter->upsertDailyFact($row);

        $this->assertSame(1, MetaAdsDailyFact::query()->count());
        $fact = MetaAdsDailyFact::query()->sole();
        $this->assertSame(100.5, $fact->spend);
        $this->assertSame(1000, $fact->impressions);
        $this->assertSame(800, $fact->reach);
    }

    public function test_upsert_daily_fact_omitted_keys_preserve_prior_values(): void
    {
        $this->upserter->upsertDailyFact([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date' => '2026-01-15',
            'spend' => 100.5,
            'currency' => 'USD',
        ]);

        // A later corrective upsert only re-sends spend; currency must be untouched.
        $this->upserter->upsertDailyFact([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date' => '2026-01-15',
            'spend' => 110.0,
        ]);

        $fact = MetaAdsDailyFact::query()->sole();
        $this->assertSame(110.0, $fact->spend);
        $this->assertSame('USD', $fact->currency, 'Omitted keys must not be clobbered.');
    }

    public function test_upsert_daily_fact_explicit_null_is_stored_as_missing_not_zero(): void
    {
        $this->upserter->upsertDailyFact([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date' => '2026-01-15',
            'reach' => null,
        ]);

        $fact = MetaAdsDailyFact::query()->sole();
        $this->assertNull($fact->reach);
        $this->assertNotSame(0, $fact->reach);
    }

    public function test_upsert_daily_actions_dedupe_by_attribution_window_default_empty_string(): void
    {
        $row = [
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date' => '2026-01-15',
            'raw_action_type' => 'lead',
            'normalized_family' => 'lead',
            'value' => 5.0,
        ];

        $this->upserter->upsertDailyAction($row);
        $this->upserter->upsertDailyAction($row);

        $this->assertSame(1, MetaAdsDailyAction::query()->count());
        $action = MetaAdsDailyAction::query()->sole();
        $this->assertSame('', $action->attribution_window);
        $this->assertSame(5.0, $action->value);
    }

    public function test_upsert_daily_actions_distinct_raw_action_types_never_collapse(): void
    {
        $this->upserter->upsertDailyActions([
            [
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => 'account',
                'provider_external_id' => $this->resource->external_id,
                'date' => '2026-01-15',
                'raw_action_type' => 'lead',
                'value' => 5.0,
            ],
            [
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => 'account',
                'provider_external_id' => $this->resource->external_id,
                'date' => '2026-01-15',
                'raw_action_type' => 'purchase',
                'value' => 2.0,
            ],
        ]);

        $this->assertSame(2, MetaAdsDailyAction::query()->count());
    }

    public function test_upsert_period_aggregate_is_idempotent(): void
    {
        $row = [
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-07',
            'metric_key' => MetaAdsPeriodAggregate::METRIC_REACH,
            'metric_value' => null,
            'status' => MetaAdsPeriodAggregate::STATUS_PENDING,
        ];

        $this->upserter->upsertPeriodAggregate($row);
        $updated = $this->upserter->upsertPeriodAggregate([...$row, 'metric_value' => 4200.0, 'status' => MetaAdsPeriodAggregate::STATUS_READY]);

        $this->assertSame(1, MetaAdsPeriodAggregate::query()->count());
        $this->assertSame('unified', $updated->attribution_context);
        $this->assertSame(4200.0, $updated->fresh()->metric_value);
        $this->assertSame(MetaAdsPeriodAggregate::STATUS_READY, $updated->fresh()->status);
    }

    public function test_update_coverage_is_idempotent_and_creates_default_status(): void
    {
        $coverage = $this->upserter->updateCoverage($this->integration, $this->resource, MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, [
            'status' => MetaAdsHistoryCoverage::STATUS_IMPORTING,
        ]);

        $this->assertSame(1, MetaAdsHistoryCoverage::query()->count());
        $this->assertSame(MetaAdsHistoryCoverage::STATUS_IMPORTING, $coverage->status);

        $this->upserter->updateCoverage($this->integration, $this->resource, MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, [
            'status' => MetaAdsHistoryCoverage::STATUS_COMPLETE,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);

        $this->assertSame(1, MetaAdsHistoryCoverage::query()->count());
        $refreshed = $coverage->fresh();
        $this->assertSame(MetaAdsHistoryCoverage::STATUS_COMPLETE, $refreshed->status);
        $this->assertSame('2026-01-01', $refreshed->start_date->toDateString());
        $this->assertSame('2026-01-31', $refreshed->end_date->toDateString());
    }
}
