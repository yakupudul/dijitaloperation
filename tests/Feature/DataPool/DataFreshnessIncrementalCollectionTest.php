<?php

namespace Tests\Feature\DataPool;

use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\IncrementalWorkReason;
use App\Enums\Collection\PlanDisposition;
use App\Enums\DataPool\FreshnessState;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Support\CollectionClock;
use App\Services\DataPool\Freshness\CollectableEndResolver;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\DataPool\Freshness\DatasetWatermarkCalculator;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Services\DataPool\Freshness\IncrementalCoveragePlanner;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\DataPool\Integrity\DataIntegrityRegistryLoader;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\DataPool\MaterializationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataFreshnessIncrementalCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN_AT = '2026-08-13 15:00:00';

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'ga4',
            'module_id' => 'ga4',
            'status' => DigitalAssetStatus::Active,
        ]);
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => 'ga4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'timezone' => 'Europe/Berlin',
            ],
        ]);
        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return array{
     *   clock: CollectionClock,
     *   collectableEnd: CollectableEndResolver,
     *   watermarks: DatasetWatermarkCalculator,
     *   evaluator: DatasetFreshnessEvaluator,
     *   policies: DataFreshnessPolicyLoader,
     *   planner: IncrementalCoveragePlanner
     * }
     */
    private function freshnessStack(?string $frozenAtUtc = null): array
    {
        $clock = new CollectionClock(CarbonImmutable::parse($frozenAtUtc ?? self::FROZEN_AT, 'UTC'));
        $collectableEnd = new CollectableEndResolver($clock);
        $watermarks = new DatasetWatermarkCalculator($collectableEnd);
        $evaluator = new DatasetFreshnessEvaluator($watermarks, $clock);
        $policies = app(DataFreshnessPolicyLoader::class);
        $planner = new IncrementalCoveragePlanner($policies, $evaluator, $watermarks);

        return compact('clock', 'collectableEnd', 'watermarks', 'evaluator', 'policies', 'planner');
    }

    private function bindPlanner(IncrementalCoveragePlanner $planner): void
    {
        $this->app->instance(IncrementalCoveragePlanner::class, $planner);
    }

    /**
     * @param  list<string>  $dates
     * @param  array<string, mixed>  $extraMeta
     */
    private function materializationWithDates(
        string $datasetId,
        array $dates,
        array $extraMeta = [],
        ?CarbonImmutable $lastCollectedAt = null,
        string $provider = 'GA4',
        ?DigitalAsset $asset = null,
        ?CoreExternalResource $resource = null,
    ): DatasetMaterialization {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);
        $asset ??= $this->asset;
        $resource ??= $this->resource;

        return DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'provider_or_source' => $provider,
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => $lastCollectedAt ?? CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHour(),
            'coverage_start_date' => $set->bounds()['start'],
            'coverage_end_date' => $set->bounds()['end'],
            'row_count_approx' => 0,
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => $set->internalGaps() !== [],
            'freshness_metadata' => array_merge([
                'successful_coverage_dates' => $dates,
                'coverage_intervals' => $set->intervals,
                'internal_gaps' => $set->internalGaps(),
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => $set->bounds()['end'],
                'last_successful_reporting_date' => $set->verifiedContiguousWatermark(),
            ], $extraMeta),
        ]);
    }

    /**
     * @return list<string>
     */
    private function contiguousDates(string $start, int $count): array
    {
        $dates = [];
        $cursor = CarbonImmutable::parse($start);
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $cursor->addDays($i)->toDateString();
        }

        return $dates;
    }

    private function datesThrough(string $endDate, ?string $startDate = '2026-08-01'): array
    {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);
        $count = $start->diffInDays($end) + 1;

        return $this->contiguousDates($startDate, $count);
    }

    private function materializeAllGa4DatasetsFresh(
        string $collectableEnd,
        ?DigitalAsset $asset = null,
        ?CoreExternalResource $resource = null,
    ): void {
        $asset ??= $this->asset;
        $resource ??= $this->resource;
        $contracts = app(DataContractRegistryLoader::class);
        $contracts->load();
        $loader = app(DataFreshnessPolicyLoader::class);

        $familyIds = collect($contracts->requestFamilies())
            ->filter(static fn (array $family): bool => ($family['provider_or_source'] ?? '') === 'GA4'
                && ! in_array((string) ($family['status'] ?? ''), ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true))
            ->pluck('id')
            ->all();

        $seen = [];
        foreach ($contracts->requirements() as $requirement) {
            $familyId = (string) ($requirement['request_family'] ?? '');
            if (! in_array($familyId, $familyIds, true)) {
                continue;
            }
            $datasetId = (string) ($requirement['dataset'] ?? '');
            if ($datasetId === '' || isset($seen[$datasetId])) {
                continue;
            }
            $seen[$datasetId] = true;

            $policy = $loader->policy($datasetId);
            if (($policy['collection_mode'] ?? '') === 'CURRENT_SNAPSHOT') {
                DatasetMaterialization::query()->create([
                    'dataset_id' => $datasetId,
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resource->id,
                    'provider_or_source' => 'GA4',
                    'contract_version' => 1,
                    'status' => MaterializationStatus::Available,
                    'last_collected_at' => CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHours(2),
                    'row_count_approx' => 1,
                    'row_count_semantics' => 'approximate_from_batches',
                    'partial' => false,
                ]);

                continue;
            }

            $this->materializationWithDates(
                $datasetId,
                $this->datesThrough($collectableEnd),
                ['last_reprocess_through' => $collectableEnd],
                provider: 'GA4',
                asset: $asset,
                resource: $resource,
            );
        }
    }

    #[Test]
    public function freshness_policy_loads_validates_and_covers_all_production_datasets(): void
    {
        $loader = app(DataFreshnessPolicyLoader::class);
        $loader->validate();

        $this->assertSame('MOXDOP_DATA_FRESHNESS_POLICY', $loader->registryId());
        $this->assertSame(1, $loader->version());
        $this->assertFalse($loader->registry()['metadata']['numeric_freshness_score']);
        $this->assertTrue($loader->registry()['metadata']['global_last_sync_forbidden']);
        $this->assertTrue($loader->globalPolicies()['no_single_global_last_sync']);

        $integrity = app(DataIntegrityRegistryLoader::class);
        foreach ($integrity->profiles() as $profile) {
            $datasetId = (string) $profile['dataset_id'];
            $policy = $loader->policy($datasetId);
            $this->assertNotNull($policy, "Missing freshness policy for [{$datasetId}]");
            if ($policy['incremental_applicable'] === false) {
                $this->assertNotEmpty($policy['non_applicable_reason']);
            }
        }

        $schema = json_decode(file_get_contents(base_path('docs/data-contracts/MOXDOP_DATA_FRESHNESS_POLICY_V1.schema.json')), true);
        $this->assertSame('MOXDOP_DATA_FRESHNESS_POLICY', $schema['properties']['metadata']['properties']['freshness_policy_registry_id']['const']);
    }

    #[Test]
    public function registry_forbids_global_last_sync_and_numeric_freshness_score(): void
    {
        $loader = app(DataFreshnessPolicyLoader::class);
        $registry = $loader->registry();

        $this->assertFalse($registry['metadata']['numeric_freshness_score']);
        $this->assertTrue($registry['metadata']['global_last_sync_forbidden']);
        $this->assertTrue($registry['metadata']['global_reprocess_window_forbidden']);
        $this->assertArrayNotHasKey('last_sync_at', $registry['global_policies']);
        $this->assertArrayNotHasKey('numeric_freshness_score', $registry['global_policies']);
    }

    #[Test]
    public function contiguous_watermark_advances_through_day_ten(): void
    {
        $dates = $this->contiguousDates('2026-08-01', 10);
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);

        $this->assertSame('2026-08-10', $set->verifiedContiguousWatermark());
        $this->assertSame('2026-08-10', $set->bounds()['end']);
    }

    #[Test]
    public function internal_gap_yields_watermark_five_and_latest_ten(): void
    {
        $dates = array_merge(
            $this->contiguousDates('2026-08-01', 5),
            $this->contiguousDates('2026-08-07', 4),
        );
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);

        $this->assertSame('2026-08-05', $set->verifiedContiguousWatermark());
        $this->assertSame('2026-08-10', $set->bounds()['end']);
        $this->assertSame([['start' => '2026-08-06', 'end' => '2026-08-06']], $set->internalGaps());
    }

    #[Test]
    public function zero_row_day_advances_verified_coverage(): void
    {
        $service = app(MaterializationService::class);
        $mat = $service->recordSuccessfulCoverageDates(
            datasetId: 'ga4_property_daily',
            digitalAssetId: $this->asset->id,
            externalResourceId: $this->resource->id,
            contractVersion: 1,
            dates: ['2026-08-10'],
            zeroRow: true,
            providerOrSource: 'GA4',
        );

        $meta = $mat->freshness_metadata;
        $this->assertContains('2026-08-10', $meta['successful_coverage_dates']);
        $this->assertContains('2026-08-10', $meta['zero_row_success_dates']);
        $this->assertSame('2026-08-10', $meta['verified_contiguous_watermark']);
    }

    #[Test]
    public function failed_refresh_does_not_add_successful_coverage_dates(): void
    {
        $service = app(MaterializationService::class);
        $mat = $service->recordSuccessfulCoverageDates(
            datasetId: 'ga4_property_daily',
            digitalAssetId: $this->asset->id,
            externalResourceId: $this->resource->id,
            contractVersion: 1,
            dates: ['2026-08-08', '2026-08-09'],
            providerOrSource: 'GA4',
        );

        $service->recordFailedRefresh($mat->fresh());
        $refreshed = $mat->fresh();
        $meta = $refreshed->freshness_metadata;

        $this->assertSame(['2026-08-08', '2026-08-09'], $meta['successful_coverage_dates']);
        $this->assertSame('2026-08-09', $meta['verified_contiguous_watermark']);
        $this->assertSame(MaterializationStatus::Stale, $refreshed->status);
    }

    #[Test]
    public function reprocess_does_not_regress_verified_watermark(): void
    {
        $service = app(MaterializationService::class);
        $dates = $this->contiguousDates('2026-08-01', 10);
        $mat = $service->recordSuccessfulCoverageDates(
            datasetId: 'ga4_property_daily',
            digitalAssetId: $this->asset->id,
            externalResourceId: $this->resource->id,
            contractVersion: 1,
            dates: $dates,
            providerOrSource: 'GA4',
        );
        $this->assertSame('2026-08-10', $mat->freshness_metadata['verified_contiguous_watermark']);

        $service->recordSuccessfulCoverageDates(
            datasetId: 'ga4_property_daily',
            digitalAssetId: $this->asset->id,
            externalResourceId: $this->resource->id,
            contractVersion: 1,
            dates: ['2026-08-05', '2026-08-06', '2026-08-07'],
            providerOrSource: 'GA4',
        );

        $after = DatasetMaterialization::query()->find($mat->id);
        $this->assertSame('2026-08-10', $after->freshness_metadata['verified_contiguous_watermark']);
    }

    #[Test]
    public function collectable_end_respects_safe_lag_and_open_day_is_not_complete(): void
    {
        $stack = $this->freshnessStack();
        $policy = $stack['policies']->policy('ga4_property_daily');

        $collectable = $stack['collectableEnd']->resolve($policy, 'UTC');
        $openDay = $stack['collectableEnd']->providerLocalReportingDate($policy, 'UTC');

        $this->assertSame('2026-08-13', $openDay);
        $this->assertSame('2026-08-11', $collectable);
        $this->assertNotSame($openDay, $collectable);
    }

    #[Test]
    public function resource_timezone_affects_gsc_collectable_end(): void
    {
        $clock = new CollectionClock(CarbonImmutable::parse('2026-08-13 22:00:00', 'UTC'));
        $resolver = new CollectableEndResolver($clock);
        $policy = app(DataFreshnessPolicyLoader::class)->policy('gsc_property_daily');

        $berlinEnd = $resolver->resolve($policy, 'Europe/Berlin');
        $pacificEnd = $resolver->resolve($policy, null);

        $this->assertSame('2026-08-11', $berlinEnd);
        $this->assertSame('2026-08-10', $pacificEnd);
        $this->assertNotSame($berlinEnd, $pacificEnd);
    }

    #[Test]
    public function freshness_evaluator_reports_expected_states(): void
    {
        $stack = $this->freshnessStack();
        $policy = $stack['policies']->policy('ga4_property_daily');
        $collectableEnd = $stack['collectableEnd']->resolve($policy, 'UTC');

        $freshMat = $this->materializationWithDates(
            'ga4_property_daily',
            $this->datesThrough($collectableEnd),
            ['last_reprocess_through' => $collectableEnd],
        );
        $fresh = $stack['evaluator']->evaluate($policy, $freshMat);
        $this->assertSame(FreshnessState::Fresh, $fresh->state);

        $dueMat = $this->materializationWithDates(
            'ga4_acquisition_channel_daily',
            $this->datesThrough(CarbonImmutable::parse($collectableEnd)->subDay()->toDateString()),
        );
        $due = $stack['evaluator']->evaluate(
            $stack['policies']->policy('ga4_acquisition_channel_daily'),
            $dueMat,
        );
        $this->assertSame(FreshnessState::Due, $due->state);

        $staleMat = $this->materializationWithDates(
            'ga4_campaign_daily',
            $this->contiguousDates('2026-08-01', 5),
            [],
            CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subDays(5),
        );
        $stale = $stack['evaluator']->evaluate(
            $stack['policies']->policy('ga4_campaign_daily'),
            $staleMat,
        );
        $this->assertSame(FreshnessState::Stale, $stale->state);

        $gapDates = array_merge($this->contiguousDates('2026-08-01', 5), $this->contiguousDates('2026-08-07', 4));
        $partialMat = $this->materializationWithDates('ga4_landing_page_daily', $gapDates);
        $partial = $stack['evaluator']->evaluate(
            $stack['policies']->policy('ga4_landing_page_daily'),
            $partialMat,
        );
        $this->assertSame(FreshnessState::Partial, $partial->state);

        $actionRequired = $stack['evaluator']->evaluate($policy, $dueMat, ['authorization_ready' => false]);
        $this->assertSame(FreshnessState::ActionRequired, $actionRequired->state);

        $providerLimited = $stack['evaluator']->evaluate($policy, $freshMat, [
            'provider_history_limited' => true,
            'provider_limitation_accepted' => false,
        ]);
        $this->assertSame(FreshnessState::ProviderLimited, $providerLimited->state);

        $integrityBlocked = $stack['evaluator']->evaluate($policy, $freshMat, ['integrity_blocked' => true]);
        $this->assertSame(FreshnessState::IntegrityBlocked, $integrityBlocked->state);
    }

    #[Test]
    public function incremental_planner_plans_one_new_period_catch_up_and_no_work_when_fresh(): void
    {
        $stack = $this->freshnessStack();
        $policy = $stack['policies']->policy('ga4_property_daily');
        $collectableEnd = $stack['collectableEnd']->resolve($policy, 'UTC');

        $oneDayMat = $this->materializationWithDates(
            'ga4_property_daily',
            $this->datesThrough(CarbonImmutable::parse($collectableEnd)->subDay()->toDateString()),
        );
        $oneDay = $stack['planner']->planDataset('ga4_property_daily', $oneDayMat);
        $this->assertTrue($oneDay->executable);
        $this->assertSame($collectableEnd, $oneDay->dateRange['end']);
        $this->assertContains(IncrementalWorkReason::NewCoverage->value, $oneDay->reasons);
        $this->assertContains(IncrementalWorkReason::LateDataReprocess->value, $oneDay->reasons);

        $catchUpMat = $this->materializationWithDates('ga4_acquisition_channel_daily', $this->contiguousDates('2026-08-01', 5));
        $catchUp = $stack['planner']->planDataset('ga4_acquisition_channel_daily', $catchUpMat);
        $this->assertTrue($catchUp->executable);
        $this->assertSame('2026-08-05', $catchUp->dateRange['start']);
        $this->assertSame($collectableEnd, $catchUp->dateRange['end']);
        $this->assertContains(IncrementalWorkReason::CatchUp->value, $catchUp->reasons);

        $freshMat = $this->materializationWithDates(
            'ga4_source_medium_daily',
            $this->datesThrough($collectableEnd),
            ['last_reprocess_through' => $collectableEnd],
        );
        $fresh = $stack['planner']->planDataset('ga4_source_medium_daily', $freshMat);
        $this->assertFalse($fresh->executable);
        $this->assertSame(PlanDisposition::AlreadySatisfied, $fresh->planDisposition);
        $this->assertSame(FreshnessState::Fresh, $fresh->freshnessState);
    }

    #[Test]
    public function datasets_use_different_late_data_reprocess_windows(): void
    {
        $policies = app(DataFreshnessPolicyLoader::class);

        $gsc = $policies->policy('gsc_property_daily');
        $conversion = $policies->policy('google_ads_conversion_action_daily');

        $this->assertSame(7, $gsc['late_data_reprocessing']['window_days']);
        $this->assertSame(30, $conversion['late_data_reprocessing']['window_days']);
    }

    #[Test]
    public function overlap_new_and_reprocess_merge_into_single_executable_envelope(): void
    {
        $stack = $this->freshnessStack();
        $collectableEnd = $stack['collectableEnd']->resolve(
            $stack['policies']->policy('ga4_property_daily'),
            'UTC',
        );

        $mat = $this->materializationWithDates('ga4_property_daily', $this->contiguousDates('2026-08-01', 5));
        $decision = $stack['planner']->planDataset('ga4_property_daily', $mat);

        $this->assertTrue($decision->executable);
        $reprocessStart = CarbonImmutable::parse($collectableEnd)->subDays(6)->toDateString();
        $this->assertSame($reprocessStart, $decision->dateRange['start']);
        $this->assertSame($collectableEnd, $decision->dateRange['end']);
        $this->assertContains(IncrementalWorkReason::CatchUp->value, $decision->reasons);
        $this->assertContains(IncrementalWorkReason::LateDataReprocess->value, $decision->reasons);
        $this->assertCount(1, array_filter(
            $decision->requestedIntervals,
            static fn (array $interval): bool => $interval['start'] === $reprocessStart && $interval['end'] === $collectableEnd,
        ));
    }

    #[Test]
    public function snapshot_dataset_uses_sla_not_daily_watermark(): void
    {
        $stack = $this->freshnessStack();
        $policy = $stack['policies']->policy('ga4_property_metadata');

        $watermark = $stack['watermarks']->calculate(null, $policy);
        $this->assertNull($watermark->currentCollectableEnd);
        $this->assertNull($watermark->verifiedContiguousWatermark);

        $neverCollected = $stack['evaluator']->evaluate($policy, null);
        $this->assertSame(FreshnessState::Due, $neverCollected->state);

        $snapshotMat = DatasetMaterialization::query()->create([
            'dataset_id' => 'ga4_property_metadata',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'GA4',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHours(2),
            'row_count_approx' => 1,
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => false,
        ]);
        $freshSnapshot = $stack['evaluator']->evaluate($policy, $snapshotMat);
        $this->assertSame(FreshnessState::Fresh, $freshSnapshot->state);
    }

    #[Test]
    public function due_query_returns_due_items_not_fresh_and_represents_action_required(): void
    {
        $stack = $this->freshnessStack();
        $this->bindPlanner($stack['planner']);

        $policy = $stack['policies']->policy('ga4_property_daily');
        $collectableEnd = $stack['collectableEnd']->resolve($policy, 'UTC');

        $this->materializationWithDates('ga4_property_daily', $this->contiguousDates('2026-08-01', 5));

        $dueItems = app(DueCollectionQueryService::class)->query([
            'digital_asset_id' => $this->asset->id,
            'provider_sources' => ['GA4'],
        ]);
        $this->assertNotEmpty($dueItems);
        $this->assertTrue(collect($dueItems)->contains(
            static fn ($item): bool => $item->datasetId === 'ga4_property_daily' && ! $item->actionRequired,
        ));

        DatasetMaterialization::query()
            ->where('dataset_id', 'ga4_property_daily')
            ->where('digital_asset_id', $this->asset->id)
            ->where('external_resource_id', $this->resource->id)
            ->delete();

        $this->materializationWithDates(
            'ga4_property_daily',
            $this->datesThrough($collectableEnd),
            ['last_reprocess_through' => $collectableEnd],
        );

        $freshItems = app(DueCollectionQueryService::class)->query([
            'digital_asset_id' => $this->asset->id,
            'provider_sources' => ['GA4'],
        ]);
        $this->assertEmpty(array_filter($freshItems, static fn ($item): bool => $item->datasetId === 'ga4_property_daily'));

        $actionItems = app(DueCollectionQueryService::class)->query([
            'digital_asset_id' => $this->asset->id,
            'provider_sources' => ['GA4'],
            'authorization_ready_by_binding_id' => [$this->binding->id => false],
            'include_action_required' => true,
        ]);
        $this->assertTrue(collect($actionItems)->contains(
            static fn ($item): bool => $item->actionRequired && $item->freshnessState === FreshnessState::ActionRequired,
        ));

        Http::assertNothingSent();
    }

    #[Test]
    public function start_incremental_collection_returns_data_current_when_nothing_due(): void
    {
        Queue::fake();
        $stack = $this->freshnessStack();
        $this->bindPlanner($stack['planner']);

        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
        ]);

        $policy = $stack['policies']->policy('ga4_property_daily');
        $collectableEnd = $stack['collectableEnd']->resolve($policy, 'UTC');
        $this->materializeAllGa4DatasetsFresh($collectableEnd);

        $result = app(StartIncrementalCollectionService::class)->startForDigitalAsset($this->asset);

        $this->assertSame('data_current', $result->outcome);
        $this->assertStringContainsString('DATA CURRENT', $result->message);
        Http::assertNothingSent();
    }

    #[Test]
    public function start_incremental_collection_persists_incremental_intent_when_work_is_due(): void
    {
        Queue::fake();
        $stack = $this->freshnessStack();
        $this->bindPlanner($stack['planner']);

        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
        ]);

        $this->materializationWithDates('ga4_property_daily', $this->contiguousDates('2026-08-01', 5));

        $result = app(StartIncrementalCollectionService::class)->startForDigitalAsset($this->asset);

        $this->assertSame('started', $result->outcome);
        $this->assertNotNull($result->collectionRun);
        $run = CollectionRun::query()->findOrFail($result->collectionRun->id);
        $this->assertSame(CollectionTriggerType::Incremental, $run->trigger_type);
        $this->assertSame('incremental_refresh', $run->metadata['collection_intent']);
        $this->assertSame('Incremental Refresh', $run->metadata['collection_intent_label']);
        $this->assertNotEmpty($run->metadata['plan_fingerprint'] ?? null);
        Http::assertNothingSent();
    }

    #[Test]
    public function routes_console_does_not_schedule_daily_collection(): void
    {
        $contents = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('Schedule::daily', $contents);
        $this->assertStringNotContainsString('collection', strtolower($contents));
    }

    #[Test]
    public function materialization_service_record_successful_coverage_dates_updates_watermark(): void
    {
        $service = app(MaterializationService::class);
        $mat = $service->recordSuccessfulCoverageDates(
            datasetId: 'ga4_property_daily',
            digitalAssetId: $this->asset->id,
            externalResourceId: $this->resource->id,
            contractVersion: 1,
            dates: $this->contiguousDates('2026-08-01', 5),
            providerOrSource: 'GA4',
        );
        $this->assertSame('2026-08-05', $mat->freshness_metadata['verified_contiguous_watermark']);

        $corrected = $service->recordSuccessfulCoverageDates(
            datasetId: 'ga4_property_daily',
            digitalAssetId: $this->asset->id,
            externalResourceId: $this->resource->id,
            contractVersion: 1,
            dates: ['2026-08-06', '2026-08-07'],
            providerOrSource: 'GA4',
        );

        $this->assertSame('2026-08-07', $corrected->freshness_metadata['verified_contiguous_watermark']);
        $this->assertSame('successful_coverage_dates', $corrected->freshness_metadata['watermark_provenance']);
    }

    #[Test]
    public function non_applicable_incremental_policies_are_explicit(): void
    {
        $loader = app(DataFreshnessPolicyLoader::class);
        foreach (['gsc_search_appearance_daily', 'gsc_url_inspection_snapshot'] as $datasetId) {
            $policy = $loader->policy($datasetId);
            $this->assertFalse($policy['incremental_applicable']);
            $this->assertNotEmpty($policy['non_applicable_reason']);
        }
    }

    #[Test]
    public function contract_registry_primary_datasets_remain_addressable_by_due_query(): void
    {
        app(DataContractRegistryLoader::class)->load();
        $this->assertGreaterThan(0, count(app(DataFreshnessPolicyLoader::class)->policies()));
    }

    #[Test]
    public function due_query_exact_binding_ids_include_sibling_assets_and_do_not_expand_to_unlisted_ids(): void
    {
        $stack = $this->freshnessStack();
        $this->bindPlanner($stack['planner']);

        $brand = $this->asset->brand;
        $siblingAsset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'ga4',
            'module_id' => 'ga4',
            'status' => DigitalAssetStatus::Active,
        ]);
        $siblingResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => 'ga4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Berlin'],
        ]);
        $siblingBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $siblingAsset->id,
            'external_resource_id' => $siblingResource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $otherBrand = Brand::factory()->create(['customer_id' => $brand->customer_id]);
        $otherBrandAsset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'ga4',
            'module_id' => 'ga4',
            'status' => DigitalAssetStatus::Active,
        ]);
        $otherBrandResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => 'ga4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $otherBrandBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $otherBrandAsset->id,
            'external_resource_id' => $otherBrandResource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $this->materializationWithDates('ga4_property_daily', $this->contiguousDates('2026-08-01', 5));
        $this->materializationWithDates(
            'ga4_property_daily',
            $this->contiguousDates('2026-08-01', 5),
            provider: 'GA4',
            asset: $siblingAsset,
            resource: $siblingResource,
        );
        $this->materializationWithDates(
            'ga4_property_daily',
            $this->contiguousDates('2026-08-01', 5),
            provider: 'GA4',
            asset: $otherBrandAsset,
            resource: $otherBrandResource,
        );

        $anchorOnly = app(DueCollectionQueryService::class)->query([
            'digital_asset_id' => $this->asset->id,
            'provider_sources' => ['GA4'],
        ]);
        $anchorBindingIds = array_values(array_unique(array_map(
            static fn ($item): int => $item->coreAssetBindingId,
            $anchorOnly,
        )));
        $this->assertContains($this->binding->id, $anchorBindingIds);
        $this->assertNotContains($siblingBinding->id, $anchorBindingIds);
        $this->assertNotContains($otherBrandBinding->id, $anchorBindingIds);

        $exact = app(DueCollectionQueryService::class)->query([
            'core_asset_binding_ids' => [$siblingBinding->id],
            'provider_sources' => ['GA4'],
        ]);
        $exactBindingIds = array_values(array_unique(array_map(
            static fn ($item): int => $item->coreAssetBindingId,
            $exact,
        )));
        $this->assertContains($siblingBinding->id, $exactBindingIds);
        $this->assertNotContains($this->binding->id, $exactBindingIds);
        $this->assertNotContains($otherBrandBinding->id, $exactBindingIds);
        $this->assertTrue(collect($exact)->contains(
            static fn ($item): bool => $item->datasetId === 'ga4_property_daily'
                && $item->digitalAssetId === $siblingAsset->id
                && ! $item->actionRequired,
        ));
    }

    #[Test]
    public function start_for_binding_ids_starts_when_only_a_sibling_binding_is_due(): void
    {
        Queue::fake();
        $stack = $this->freshnessStack();
        $this->bindPlanner($stack['planner']);

        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
        ]);

        $policy = $stack['policies']->policy('ga4_property_daily');
        $collectableEnd = $stack['collectableEnd']->resolve($policy, 'UTC');
        $this->materializeAllGa4DatasetsFresh($collectableEnd);

        $siblingAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'type' => 'ga4',
            'module_id' => 'ga4',
            'status' => DigitalAssetStatus::Active,
        ]);
        $siblingResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => 'ga4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Berlin'],
        ]);
        $siblingBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $siblingAsset->id,
            'external_resource_id' => $siblingResource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->materializationWithDates(
            'ga4_property_daily',
            $this->contiguousDates('2026-08-01', 5),
            provider: 'GA4',
            asset: $siblingAsset,
            resource: $siblingResource,
        );

        $anchorOnly = app(StartIncrementalCollectionService::class)->startForDigitalAsset($this->asset);
        $this->assertSame('data_current', $anchorOnly->outcome);

        $result = app(StartIncrementalCollectionService::class)->startForBindingIds(
            [$this->binding->id, $siblingBinding->id],
        );
        $this->assertSame('started', $result->outcome);
        $this->assertNotNull($result->collectionRun);
        $plannedBindingIds = $result->collectionRun->resourceRuns()->pluck('core_asset_binding_id')->all();
        $this->assertContains($siblingBinding->id, $plannedBindingIds);
        $this->assertNotContains($this->binding->id, $plannedBindingIds);
        $this->assertTrue(collect($result->decisions)->contains(
            static fn (array $row): bool => ($row['core_asset_binding_id'] ?? null) === $siblingBinding->id
                && ($row['dataset_id'] ?? null) === 'ga4_property_daily',
        ));
        Http::assertNothingSent();
    }
}
