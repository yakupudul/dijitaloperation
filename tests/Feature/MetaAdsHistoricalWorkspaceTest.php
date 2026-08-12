<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Jobs\Async\MetaHistoricalGapEnrichJob;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\ComparisonPeriod;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoxDop\MetaAds\History\MetaHistoricalUpserter;
use MoxDop\MetaAds\Models\MetaAdsEntity;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Models\MetaAdsPeriodAggregate;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;
use MoxDop\MetaAds\Workspace\MetaWorkspaceFilters;
use Tests\TestCase;

/**
 * Proves the Meta Ads Expert Workspace is powered by the local historical store:
 * a covered range loads KPIs / campaigns / trend immediately (no Analyze gate, no
 * synchronous provider call), an uncovered-but-recent range silently queues gap
 * enrichment, the delivered-in-period filter is honoured, and no data-architecture
 * jargon leaks onto the primary operator surface.
 */
class MetaAdsHistoricalWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $asset;

    private CoreIntegration $integration;

    private CoreExternalResource $resource;

    private MetaHistoricalUpserter $upserter;

    /** @var array{current: array{start: string, end: string}, previous: array{start: string, end: string}} */
    private array $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Obezite Brand']);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'name' => 'Obezite ve Estetik',
        ]);

        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_744654160596455',
            'display_name' => 'Obezite ve Estetik',
            'metadata' => [
                'business_name' => 'Test BM',
                'currency' => 'TRY',
                'timezone_name' => 'Europe/Istanbul',
            ],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $this->upserter = app(MetaHistoricalUpserter::class);
        $this->period = ComparisonPeriod::forPreset(ComparisonPeriod::PRESET_LAST_7);
        MetaWorkspaceFilters::put((int) $this->asset->id, [
            'period_preset' => ComparisonPeriod::PRESET_LAST_7,
            'compare' => true,
            'delivery' => MetaWorkspaceFilters::DELIVERY_DELIVERED,
        ]);
    }

    public function test_covered_period_loads_local_history_immediately_without_analyze(): void
    {
        $this->seedCoveredHistory();

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);

        $this->assertSame('covered', $data['history']['state']);
        $this->assertTrue($data['period_matched']);
        $this->assertFalse($data['needs_analyze']);
        $this->assertNull($data['history']['message']);

        $this->assertNotEmpty($data['kpis']);
        $this->assertSame('spend', $data['kpis'][0]['key']);

        // Reach/frequency come from the exact-period aggregate cache, never a summed range.
        $reach = collect($data['kpis_secondary'])->firstWhere('key', 'reach');
        $this->assertNotNull($reach);
        $this->assertSame(5000, $reach['value']);
        $frequency = collect($data['kpis_secondary'])->firstWhere('key', 'frequency');
        $this->assertNotNull($frequency);
        $this->assertSame(1.5, $frequency['value']);

        // A daily trend is always available for a covered range once history is imported.
        $this->assertTrue($data['trend']['available']);

        // Campaign snapshot is populated straight from the historical store.
        $this->assertContains('Delivered Campaign', collect($data['campaigns'])->pluck('name')->all());
    }

    public function test_covered_range_does_not_call_meta_or_queue_gap_enrich(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();

        $this->seedCoveredHistory();

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            // Re-selecting a fully covered range must not fetch from Meta or enqueue enrichment.
            ->call('setMetaWorkspaceFilter', 'period_preset', ComparisonPeriod::PRESET_LAST_7);

        Http::assertNothingSent();
        Queue::assertNotPushed(MetaHistoricalGapEnrichJob::class);

        $this->assertNull(
            Run::query()
                ->where('digital_asset_id', $this->asset->id)
                ->where('metadata->operation_type', AsyncOperationTypes::META_HISTORY_GAP_ENRICH)
                ->first(),
        );
    }

    public function test_uncovered_recent_range_queues_gap_enrich_in_background(): void
    {
        Queue::fake();

        // No coverage / no facts seeded: the recent range is not imported but is inside the
        // provider availability window, so it must be prepared silently in the background.
        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->call('setMetaWorkspaceFilter', 'period_preset', ComparisonPeriod::PRESET_LAST_7);

        Queue::assertPushed(MetaHistoricalGapEnrichJob::class);

        $run = Run::query()
            ->where('digital_asset_id', $this->asset->id)
            ->where('metadata->operation_type', AsyncOperationTypes::META_HISTORY_GAP_ENRICH)
            ->first();

        $this->assertNotNull($run);
        $this->assertSame($this->period['current']['start'], data_get($run->metadata, 'gap_from'));
        $this->assertSame($this->period['current']['end'], data_get($run->metadata, 'gap_to'));

        // The operator surface communicates preparation, not an "Analyze this period" gate.
        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertSame('preparing', $data['history']['state']);
        $this->assertFalse($data['needs_analyze']);
        $this->assertStringContainsString('Preparing missing history', (string) $data['history']['message']);
    }

    public function test_delivered_in_period_filter_hides_zero_delivery_campaigns_by_default(): void
    {
        $this->seedCoveredHistory();

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $names = collect($data['campaigns'])->pluck('name')->all();
        $this->assertContains('Delivered Campaign', $names);
        $this->assertNotContains('Zero Campaign', $names);

        MetaWorkspaceFilters::put((int) $this->asset->id, ['delivery' => MetaWorkspaceFilters::DELIVERY_ALL]);
        $all = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertContains('Zero Campaign', collect($all['campaigns'])->pluck('name')->all());
    }

    public function test_primary_surface_hides_data_architecture_jargon(): void
    {
        $this->seedCoveredHistory();

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Obezite ve Estetik')
            ->assertSee('Campaigns')
            ->assertSee('Creatives')
            ->assertSee('Insights')
            ->assertDontSee('Evidence')
            ->assertDontSee('ExternalResource')
            ->assertDontSee('MetaHistoricalQueryService');
    }

    /**
     * Seeds a fully-covered historical range: coverage marker, account + campaign daily
     * facts, account/campaign daily actions, and an exact-period reach/frequency cache.
     */
    private function seedCoveredHistory(): void
    {
        $start = $this->period['current']['start'];
        $end = $this->period['current']['end'];

        $this->upserter->updateCoverage($this->integration, $this->resource, MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, [
            'status' => MetaAdsHistoryCoverage::STATUS_COMPLETE,
            'start_date' => CarbonImmutable::parse($start)->subDays(5)->toDateString(),
            'end_date' => $end,
            'last_successful_sync_at' => now(),
        ]);

        $this->upserter->upsertEntity($this->integration, $this->resource, [
            'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
            'provider_external_id' => 'cmp_1',
            'parent_provider_external_id' => $this->resource->external_id,
            'name' => 'Delivered Campaign',
            'status' => 'ACTIVE',
            'objective' => 'OUTCOME_LEADS',
        ]);
        $this->upserter->upsertEntity($this->integration, $this->resource, [
            'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
            'provider_external_id' => 'cmp_zero',
            'parent_provider_external_id' => $this->resource->external_id,
            'name' => 'Zero Campaign',
            'status' => 'ACTIVE',
            'objective' => 'OUTCOME_AWARENESS',
        ]);

        $cursor = CarbonImmutable::parse($start);
        $endDate = CarbonImmutable::parse($end);
        while ($cursor->lessThanOrEqualTo($endDate)) {
            $date = $cursor->toDateString();

            $this->upserter->upsertDailyFact([
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => 'account',
                'provider_external_id' => $this->resource->external_id,
                'date' => $date,
                'spend' => 20.0,
                'impressions' => 1000,
                'clicks' => 40,
                'link_clicks' => 30,
                'reach' => 700,
                'frequency' => 1.3,
            ]);

            $this->upserter->upsertDailyFact([
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
                'provider_external_id' => 'cmp_1',
                'parent_provider_external_id' => $this->resource->external_id,
                'date' => $date,
                'spend' => 18.0,
                'impressions' => 800,
                'clicks' => 30,
                'link_clicks' => 24,
            ]);

            // Zero-delivery campaign: has fact rows, but no spend and no impressions.
            $this->upserter->upsertDailyFact([
                'core_integration_id' => $this->integration->id,
                'core_external_resource_id' => $this->resource->id,
                'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
                'provider_external_id' => 'cmp_zero',
                'parent_provider_external_id' => $this->resource->external_id,
                'date' => $date,
                'spend' => 0.0,
                'impressions' => 0,
            ]);

            $this->upserter->upsertDailyActions([
                [
                    'core_integration_id' => $this->integration->id,
                    'core_external_resource_id' => $this->resource->id,
                    'entity_type' => 'account',
                    'provider_external_id' => $this->resource->external_id,
                    'date' => $date,
                    'raw_action_type' => 'lead',
                    'normalized_family' => 'lead',
                    'value' => 2.0,
                ],
                [
                    'core_integration_id' => $this->integration->id,
                    'core_external_resource_id' => $this->resource->id,
                    'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
                    'provider_external_id' => 'cmp_1',
                    'date' => $date,
                    'raw_action_type' => 'lead',
                    'normalized_family' => 'lead',
                    'value' => 2.0,
                ],
            ]);

            $cursor = $cursor->addDay();
        }

        $this->upserter->upsertPeriodAggregate([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date_from' => $start,
            'date_to' => $end,
            'metric_key' => MetaAdsPeriodAggregate::METRIC_REACH,
            'metric_value' => 5000.0,
            'status' => MetaAdsPeriodAggregate::STATUS_READY,
        ]);
        $this->upserter->upsertPeriodAggregate([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $this->resource->external_id,
            'date_from' => $start,
            'date_to' => $end,
            'metric_key' => MetaAdsPeriodAggregate::METRIC_FREQUENCY,
            'metric_value' => 1.5,
            'status' => MetaAdsPeriodAggregate::STATUS_READY,
        ]);
    }
}
