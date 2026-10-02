<?php

namespace Tests\Feature\CollectionScheduler;

use App\Enums\Collection\CollectionLifecycleAction;
use App\Enums\Collection\CollectionLifecycleIntent;
use App\Enums\Collection\CollectionPlanningBlockReason;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\IncrementalWorkReason;
use App\Enums\CollectionScheduleStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CollectionSchedule;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\RecurringOccurrence;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Support\CollectionClock;
use App\Services\CollectionScheduler\CollectionLifecyclePlanner;
use App\Services\CollectionScheduler\CollectionSchedulingPolicyRegistry;
use App\Services\CollectionScheduler\ExecuteCollectionLifecycleService;
use App\Services\CollectionScheduler\LatestSafeReportingWindowResolver;
use App\Services\DataPool\Freshness\CollectableEndResolver;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\DataPool\Freshness\DatasetWatermarkCalculator;
use App\Services\DataPool\Freshness\IncrementalCoveragePlanner;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\RecurringAutomation\Adapters\CollectionScheduleAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectionSchedulerLifecycleTest extends TestCase
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
        Queue::fake();

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
            'metadata' => ['timezone' => 'Europe/Berlin'],
        ]);
        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $this->bindFrozenPlanner();
    }

    private function bindFrozenPlanner(): void
    {
        $clock = new CollectionClock(CarbonImmutable::parse(self::FROZEN_AT, 'UTC'));
        $collectableEnd = new CollectableEndResolver($clock);
        $watermarks = new DatasetWatermarkCalculator($collectableEnd);
        $evaluator = new DatasetFreshnessEvaluator($watermarks, $clock);
        $policies = app(DataFreshnessPolicyLoader::class);
        $planner = new IncrementalCoveragePlanner($policies, $evaluator, $watermarks);
        $this->app->instance(IncrementalCoveragePlanner::class, $planner);
        $this->app->instance(CollectableEndResolver::class, $collectableEnd);
        $this->app->instance(DatasetWatermarkCalculator::class, $watermarks);
    }

    /**
     * @param  list<string>  $dates
     */
    private function materializationWithDates(string $datasetId, array $dates): DatasetMaterialization
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);

        return DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'GA4',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHour(),
            'coverage_start_date' => $set->bounds()['start'],
            'coverage_end_date' => $set->bounds()['end'],
            'row_count_approx' => 0,
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => $set->internalGaps() !== [],
            'freshness_metadata' => [
                'successful_coverage_dates' => $dates,
                'coverage_intervals' => $set->intervals,
                'internal_gaps' => $set->internalGaps(),
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => $set->bounds()['end'],
                'last_successful_reporting_date' => $set->verifiedContiguousWatermark(),
            ],
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

    private function materializeAllGa4Through(string $collectableEnd, ?string $reprocessThrough = null): void
    {
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
                    'digital_asset_id' => $this->asset->id,
                    'external_resource_id' => $this->resource->id,
                    'provider_or_source' => 'GA4',
                    'contract_version' => 1,
                    'status' => MaterializationStatus::Available,
                    'last_collected_at' => CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHours(2),
                    'partial' => false,
                ]);

                continue;
            }

            $dates = $this->contiguousDates('2026-08-01', CarbonImmutable::parse('2026-08-01')->diffInDays(CarbonImmutable::parse($collectableEnd)) + 1);
            $mat = $this->materializationWithDates($datasetId, $dates);
            if ($reprocessThrough !== null) {
                $meta = $mat->freshness_metadata;
                $meta['last_reprocess_through'] = $reprocessThrough;
                $mat->forceFill(['freshness_metadata' => $meta])->save();
            }
        }
    }

    #[Test]
    public function policy_registry_is_dataset_specific_and_excludes_dataforseo(): void
    {
        $registry = app(CollectionSchedulingPolicyRegistry::class);
        $this->assertFalse($registry->isDataForSeoRoutinelyScheduled());
        $this->assertNotContains('DATAFORSEO', $registry->schedulableProviders());

        $policy = $registry->policy('ga4_property_daily');
        $this->assertNotNull($policy);
        $this->assertSame('GA4', $policy->providerOrSource);
        $this->assertTrue($policy->lateDataRepairEnabled);
        $this->assertIsInt($policy->safeCollectionLagDays);
        $this->assertNotSame('', $policy->policyFingerprint);

        $this->assertNull($registry->policy('totally_fake_dataset_xyz'));
        $this->assertFalse(class_exists('App\\Services\\CollectionScheduler\\CollectionSchedulerV2'));
        $this->assertFalse(class_exists('App\\Models\\WatermarkV2'));
    }

    #[Test]
    public function latest_safe_frontier_never_uses_current_date_as_safe(): void
    {
        $resolver = app(LatestSafeReportingWindowResolver::class);
        $window = $resolver->resolve(
            'ga4_property_daily',
            'Europe/Berlin',
            CarbonImmutable::parse(self::FROZEN_AT, 'UTC'),
        );

        $this->assertTrue($window->isAvailable());
        $this->assertNotNull($window->latestSafeDate);
        $this->assertNotSame($window->providerLocalReportingDate, $window->latestSafeDate);
        $this->assertTrue($window->latestSafeDate < $window->providerLocalReportingDate);
    }

    #[Test]
    public function new_dataset_plans_initial_backfill(): void
    {
        $planner = app(CollectionLifecyclePlanner::class);
        $decision = $planner->planForDigitalAsset($this->asset);

        $this->assertSame(CollectionLifecycleAction::InitialBackfill, $decision->action);
        $this->assertSame(CollectionLifecycleIntent::InitialBackfill, $decision->intent);
        $this->assertTrue($decision->isExecutable());
    }

    #[Test]
    public function verified_watermark_behind_safe_frontier_plans_incremental_or_catch_up(): void
    {
        // Berlin 2026-08-13 → collectable end with lag 2 ≈ 2026-08-11
        $this->materializeAllGa4Through('2026-08-10', '2026-08-10');

        $planner = app(CollectionLifecyclePlanner::class);
        $decision = $planner->planForDigitalAsset($this->asset);

        $this->assertContains($decision->action, [
            CollectionLifecycleAction::Incremental,
            CollectionLifecycleAction::CatchUp,
        ]);
        $this->assertNotSame(CollectionLifecycleAction::InitialBackfill, $decision->action);
    }

    #[Test]
    public function watermark_equal_safe_frontier_with_recent_reprocess_is_no_work_or_repair(): void
    {
        $resolver = app(LatestSafeReportingWindowResolver::class);
        $end = $resolver->resolve('ga4_property_daily', 'Europe/Berlin', CarbonImmutable::parse(self::FROZEN_AT, 'UTC'))->latestSafeDate;
        $this->assertNotNull($end);
        $this->materializeAllGa4Through($end, $end);

        $planner = app(CollectionLifecyclePlanner::class);
        $decision = $planner->planForDigitalAsset($this->asset);

        // When coverage matches the safe frontier, planner must not restart Initial Backfill.
        $this->assertNotSame(CollectionLifecycleAction::InitialBackfill, $decision->action);
        $this->assertNotSame(CollectionLifecycleIntent::InitialBackfill, $decision->intent);
        $this->assertContains($decision->action, [
            CollectionLifecycleAction::NoWork,
            CollectionLifecycleAction::LateDataRepair,
            CollectionLifecycleAction::Blocked,
        ]);
    }

    #[Test]
    public function known_gap_plans_catch_up_not_late_repair(): void
    {
        $dates = array_merge(
            $this->contiguousDates('2026-08-01', 5),
            $this->contiguousDates('2026-08-08', 4),
        );
        // Leave an internal gap on primary datasets by writing gap materializations for all GA4 historical datasets.
        $contracts = app(DataContractRegistryLoader::class);
        $contracts->load();
        $loader = app(DataFreshnessPolicyLoader::class);
        $seen = [];
        foreach ($contracts->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === null) {
                continue;
            }
            $datasetId = (string) ($requirement['dataset'] ?? '');
            $policy = $loader->policy($datasetId);
            if ($policy === null || ($policy['provider_or_source'] ?? '') !== 'GA4') {
                continue;
            }
            if (($policy['collection_mode'] ?? '') === 'CURRENT_SNAPSHOT') {
                continue;
            }
            if (isset($seen[$datasetId])) {
                continue;
            }
            $seen[$datasetId] = true;
            $this->materializationWithDates($datasetId, $dates);
        }

        $planner = app(CollectionLifecyclePlanner::class);
        $decision = $planner->planForDigitalAsset($this->asset);

        $this->assertSame(CollectionLifecycleAction::CatchUp, $decision->action);
        $this->assertSame(CollectionLifecycleIntent::CatchUp, $decision->intent);
    }

    #[Test]
    public function paused_schedule_blocks_planning(): void
    {
        $schedule = CollectionSchedule::query()->create([
            'customer_id' => $this->asset->brand->customer_id,
            'brand_id' => $this->asset->brand_id,
            'digital_asset_id' => $this->asset->id,
            'frequency' => RecurringFrequency::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Berlin',
            'local_time' => '06:00',
            'misfire_policy' => RecurringMisfirePolicy::CatchUpBounded,
            'status' => CollectionScheduleStatus::Paused,
        ]);

        $planner = app(CollectionLifecyclePlanner::class);
        $decision = $planner->planForDigitalAsset($this->asset, ['schedule' => $schedule]);

        $this->assertSame(CollectionLifecycleAction::Blocked, $decision->action);
        $this->assertSame(CollectionPlanningBlockReason::SchedulePaused, $decision->blockReason);
    }

    #[Test]
    public function unbound_resource_blocks_collection(): void
    {
        $this->binding->forceFill(['status' => CoreAssetBinding::STATUS_DISABLED])->save();

        $planner = app(CollectionLifecyclePlanner::class);
        $decision = $planner->planForDigitalAsset($this->asset);

        $this->assertSame(CollectionLifecycleAction::Blocked, $decision->action);
        $this->assertSame(CollectionPlanningBlockReason::ResourceUnbound, $decision->blockReason);
    }

    #[Test]
    public function lifecycle_execution_starts_initial_backfill_through_orchestrator(): void
    {
        $service = app(ExecuteCollectionLifecycleService::class);
        $result = $service->executeForDigitalAsset($this->asset);

        $this->assertSame('started', $result->outcome);
        $this->assertSame(CollectionLifecycleIntent::InitialBackfill, $result->intent);
        $this->assertNotNull($result->collectionRun);
        $this->assertSame(CollectionTriggerType::InitialBackfill, $result->collectionRun->trigger_type);
        $this->assertSame(CollectionLifecycleIntent::InitialBackfill->value, $result->collectionRun->metadata['collection_intent']);
        $this->assertNotNull($result->plan);
        $this->assertSame($result->plan->planFingerprint, $result->collectionRun->metadata['plan_fingerprint']);
    }

    #[Test]
    public function queue_retry_reuses_same_logical_plan_run(): void
    {
        $service = app(ExecuteCollectionLifecycleService::class);
        $first = $service->executeForDigitalAsset($this->asset, null, null, [
            'idempotency_suffix' => 'occ:test-1',
        ]);
        $second = $service->executeForDigitalAsset($this->asset, null, null, [
            'idempotency_suffix' => 'occ:test-1',
        ]);

        $this->assertSame('started', $first->outcome);
        $this->assertTrue(in_array($second->outcome, ['started', 'active_equivalent'], true));
        $this->assertSame($first->collectionRun?->id, $second->collectionRun?->id);
        $this->assertSame(1, CollectionRun::query()->where('trigger_type', CollectionTriggerType::InitialBackfill)->count());
    }

    #[Test]
    public function no_work_does_not_fabricate_collection_run(): void
    {
        $resolver = app(LatestSafeReportingWindowResolver::class);
        $end = $resolver->resolve('ga4_property_daily', 'Europe/Berlin', CarbonImmutable::parse(self::FROZEN_AT, 'UTC'))->latestSafeDate;
        $this->materializeAllGa4Through($end, $end);

        // Force evaluator to treat reprocess as current by pinning last_reprocess_through.
        $service = app(ExecuteCollectionLifecycleService::class);
        $result = $service->executeForDigitalAsset($this->asset);

        if ($result->outcome === 'no_work') {
            $this->assertNull($result->collectionRun);
            $this->assertSame(0, CollectionRun::query()->count());
        } else {
            // Late repair may still be due — must not be Initial Backfill.
            $this->assertNotSame(CollectionLifecycleIntent::InitialBackfill, $result->intent);
        }
    }

    #[Test]
    public function collection_schedule_adapter_uses_lifecycle_not_direct_collectors(): void
    {
        $schedule = CollectionSchedule::query()->create([
            'customer_id' => $this->asset->brand->customer_id,
            'brand_id' => $this->asset->brand_id,
            'digital_asset_id' => $this->asset->id,
            'frequency' => RecurringFrequency::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Berlin',
            'local_time' => '06:00',
            'misfire_policy' => RecurringMisfirePolicy::CatchUpBounded,
            'status' => CollectionScheduleStatus::Active,
        ]);

        $occurrence = RecurringOccurrence::query()->create([
            'schedule_kind' => RecurringScheduleKind::Collection,
            'domain_schedule_id' => $schedule->id,
            'scheduled_for' => CarbonImmutable::parse('2026-08-13 04:00:00', 'UTC'),
            'timezone_snapshot' => 'Europe/Berlin',
            'recurrence_spec_fingerprint' => 'test',
            'status' => RecurringOccurrenceStatus::Queued,
            'occurrence_key' => 'collection:'.$schedule->id.':2026-08-13T04:00:00Z',
            'attempt_count' => 0,
        ]);

        $adapter = app(CollectionScheduleAdapter::class);
        $result = $adapter->execute($occurrence);

        $this->assertSame(RecurringOccurrenceStatus::Completed, $result->status);
        $this->assertNotNull($result->domainRunId);
        $run = CollectionRun::query()->find($result->domainRunId);
        $this->assertNotNull($run);
        $this->assertSame(CollectionLifecycleIntent::InitialBackfill->value, $run->metadata['collection_intent']);
        $this->assertSame(0, Http::recorded()->count());
    }

    #[Test]
    public function intent_from_reasons_keeps_four_modes_distinct(): void
    {
        $planner = app(CollectionLifecyclePlanner::class);

        $this->assertSame(
            CollectionLifecycleIntent::CatchUp,
            $planner->intentFromReasons([IncrementalWorkReason::GapRecovery->value]),
        );
        $this->assertSame(
            CollectionLifecycleIntent::CatchUp,
            $planner->intentFromReasons([IncrementalWorkReason::CatchUp->value]),
        );
        $this->assertSame(
            CollectionLifecycleIntent::Incremental,
            $planner->intentFromReasons([IncrementalWorkReason::NewCoverage->value]),
        );
        $this->assertSame(
            CollectionLifecycleIntent::LateDataRepair,
            $planner->intentFromReasons([IncrementalWorkReason::LateDataReprocess->value]),
        );
        $this->assertSame(
            CollectionLifecycleIntent::CatchUp,
            $planner->intentFromReasons([
                IncrementalWorkReason::LateDataReprocess->value,
                IncrementalWorkReason::CatchUp->value,
            ]),
        );
    }

    #[Test]
    public function run_now_uses_same_planner_and_does_not_default_to_full_backfill_when_current(): void
    {
        $resolver = app(LatestSafeReportingWindowResolver::class);
        $end = $resolver->resolve('ga4_property_daily', 'Europe/Berlin', CarbonImmutable::parse(self::FROZEN_AT, 'UTC'))->latestSafeDate;
        $this->materializeAllGa4Through($end, $end);

        $service = app(ExecuteCollectionLifecycleService::class);
        $result = $service->runNow($this->asset);

        $this->assertNotSame(CollectionLifecycleIntent::InitialBackfill, $result->intent);
        if ($result->outcome === 'no_work') {
            $this->assertNull($result->collectionRun);
        }
    }

    #[Test]
    public function no_arbitrary_execution_kinds_exist(): void
    {
        $this->assertNull(CollectionLifecycleIntent::tryFrom('FULL_REFRESH_RECURRING'));
        $this->assertNull(CollectionLifecycleIntent::tryFrom('CUSTOM_SQL'));
        $this->assertNull(CollectionLifecycleIntent::tryFrom('AI_SELECTED_MODE'));
        $this->assertNull(CollectionLifecycleAction::tryFrom('RUN_SHELL'));
    }
}
