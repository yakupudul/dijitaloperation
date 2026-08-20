<?php

namespace Tests\Feature\Analysis;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\RecommendationOrigin;
use App\Jobs\Async\EvaluateFindingsForAssetJob;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Analysis\CollectedFactsAnalysisService;
use App\Services\CreateTaskFromRecommendation;
use App\Services\CrossAssetWebsiteGoogleAdsLandingConsistencyService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\Findings\FindingEvaluationService;
use App\Services\GoogleAdsLandingFinalUrlsCollectService;
use App\Services\Recommendations\CreateRecommendationFromFinding;
use App\Services\Tasks\TaskLifecycleService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use App\Support\Tasks\TaskOutcomeStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MoxDop\GoogleAds\Findings\GoogleAdsPerformanceBoundEvidenceEvaluator;
use MoxDop\GoogleAds\Findings\PerformanceFindingsCatalog as GoogleAdsFindingsCatalog;
use MoxDop\MetaAds\Findings\MetaAdsFindingsCatalog;
use MoxDop\Website\Diagnosis\DocumentHeadCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectedFactsOperationalSynthesisTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function website_title_missing_vertical_is_idempotent_and_closes_outcome_loop(): void
    {
        $this->travelTo('2026-08-20 10:00:00');
        $website = $this->makeWebsiteAsset('https://atlas.example/');
        $collectionRun = $this->makeCollectionRun($website);
        $this->insertWebsiteMetadata($website, $collectionRun->id, '2026-08-20 09:00:00', [
            'title' => null,
            'title_present' => false,
            'meta_description' => 'Atlas Dental clinic in Istanbul offers implant and smile design services for visiting patients.',
            'meta_description_present' => true,
        ]);

        $first = app(CollectedFactsAnalysisService::class)->analyze($website);
        $this->assertTrue($first->evaluated);
        $this->assertTrue($first->evaluationSuccessful);
        $this->assertContains(DocumentHeadCatalog::RULE_TITLE_MISSING, $first->evaluatedRuleIds);
        $this->assertNotContains(DocumentHeadCatalog::RULE_CHARSET_MISSING, $first->evaluatedRuleIds);
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();

        $finding = Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->firstOrFail();
        $this->assertSame(DocumentHeadCatalog::RULE_TITLE_MISSING, $finding->fingerprint);
        $this->assertSame('website-diagnosis', $finding->source_module);
        $this->assertSame('open', $finding->status);
        $this->assertSame($first->run?->id, $finding->last_run_id);
        $this->assertSame($collectionRun->id, $first->provenance['collection_run_id']);

        $recommendation = Recommendation::query()->where('finding_id', $finding->id)->firstOrFail();
        $this->assertSame(RecommendationOrigin::DeterministicTemplate->value, $recommendation->origin);
        $this->assertSame('open', $recommendation->status);

        $second = app(CollectedFactsAnalysisService::class)->analyze($website);
        $this->assertTrue($second->evaluationSuccessful);
        $this->assertSame(1, Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->count());
        $this->assertSame($finding->id, Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->value('id'));
        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame(0, Task::query()->count());

        $task = app(CreateTaskFromRecommendation::class)->create($recommendation);
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
        $this->assertSame($website->id, $task->digital_asset_id);
        $this->assertSame($recommendation->id, $task->recommendation_id);
        $snapshot = $task->snapshot_json;
        $this->assertSame($finding->id, data_get($snapshot, 'finding.id'));
        $this->assertSame($finding->fingerprint, data_get($snapshot, 'finding.fingerprint'));

        $this->travelTo('2026-08-20 10:05:00');
        $task = app(TaskLifecycleService::class)->complete($task, [
            'completion_note' => 'Published a title outside MoxDOP.',
        ], $this->admin);
        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->outcome_status);
        $this->assertFalse(data_get($task->outcome_json, 'causal_attribution'));
        $this->assertSame($snapshot, $task->fresh()->snapshot_json);
        $this->assertSame('open', $finding->fresh()->status);

        $this->travelTo('2026-08-20 11:00:00');
        $this->insertWebsiteMetadata($website, $collectionRun->id, '2026-08-20 10:30:00', [
            'title' => 'Atlas Dental',
            'title_present' => true,
            'meta_description' => 'Atlas Dental clinic in Istanbul offers implant and smile design services for visiting patients.',
            'meta_description_present' => true,
        ]);

        $followUp = app(CollectedFactsAnalysisService::class)->analyze($website);
        $this->assertTrue($followUp->evaluationSuccessful);
        $this->assertSame('resolved', $finding->fresh()->status);
        $task = $task->fresh();
        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->outcome_status);
        $this->assertSame($followUp->run?->id, $task->outcome_run_id);
        $this->assertFalse(data_get($task->outcome_json, 'causal_attribution'));
        $this->assertSame(0, Finding::query()->where('digital_asset_id', '!=', $website->id)->count());
        $this->assertFalse(class_exists('App\\Models\\LearningCandidate'));
        $this->assertFalse(class_exists('App\\Models\\AgencyKnowledge'));
        $this->assertFalse(class_exists('App\\Services\\Findings\\FindingEngineV2'));
    }

    #[Test]
    public function missing_website_snapshots_skip_without_zero_or_resolve(): void
    {
        $website = $this->makeWebsiteAsset('https://atlas.example/');
        Finding::factory()->create([
            'digital_asset_id' => $website->id,
            'source_module' => 'website-diagnosis',
            'fingerprint' => DocumentHeadCatalog::RULE_TITLE_MISSING,
            'status' => 'open',
        ]);

        $result = app(CollectedFactsAnalysisService::class)->analyze($website);
        $this->assertFalse($result->evaluated);
        $this->assertSame('missing_website_metadata_snapshot', $result->skipReason);
        $this->assertSame('open', Finding::query()->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)->value('status'));
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function google_ads_campaign_zero_conversions_stays_on_bound_resource_and_reopens(): void
    {
        [$assetA, $resourceA, $collectionA] = $this->makeGoogleAdsAsset();
        [$assetB, $resourceB] = $this->makeGoogleAdsAsset();

        $this->insertGoogleAdsCampaignDaily($assetA, $resourceA, $collectionA->id, '2026-08-19', '111', 75_000_000, 0);
        $this->sealCompletedDailyFacts($assetA, $resourceA, $collectionA, 'google_ads_campaign_daily', 'GOOGLE_ADS', 'google_ads_campaign_daily', '2026-08-19');
        $this->insertGoogleAdsCampaignDaily($assetB, $resourceB, null, '2026-08-19', '111', 90_000_000, 0);

        $first = app(CollectedFactsAnalysisService::class)->analyze($assetA);
        $this->assertTrue($first->evaluated);
        $this->assertContains(GoogleAdsFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS, $first->evaluatedRuleIds);
        $this->assertNotContains(GoogleAdsFindingsCatalog::RULE_CONVERSIONS_DECLINE, $first->evaluatedRuleIds);

        $fingerprint = GoogleAdsFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS.':111';
        $finding = Finding::query()
            ->where('digital_asset_id', $assetA->id)
            ->where('fingerprint', $fingerprint)
            ->firstOrFail();
        $this->assertSame('google-ads', $finding->source_module);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $assetB->id)->count());
        $this->assertSame($collectionA->id, Evidence::query()->where('run_id', $first->run?->id)->value('collection_run_id'));
        $this->assertSame(0, Task::query()->count());

        $repeat = app(CollectedFactsAnalysisService::class)->analyze($assetA);
        $this->assertSame(1, Finding::query()->where('digital_asset_id', $assetA->id)->count());
        $this->assertSame($finding->id, Finding::query()->where('digital_asset_id', $assetA->id)->value('id'));

        DB::table('google_ads_campaign_daily')
            ->where('digital_asset_id', $assetA->id)
            ->where('campaign_id', '111')
            ->update(['conversions' => 4, 'updated_at' => now()]);

        $resolved = app(CollectedFactsAnalysisService::class)->analyze($assetA);
        $this->assertSame('resolved', $finding->fresh()->status);
        $this->assertGreaterThan(0, $resolved->findings['resolved']);

        DB::table('google_ads_campaign_daily')
            ->where('digital_asset_id', $assetA->id)
            ->where('campaign_id', '111')
            ->update(['conversions' => 0, 'updated_at' => now()]);

        $reopened = app(CollectedFactsAnalysisService::class)->analyze($assetA);
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertGreaterThan(0, $reopened->findings['reopened']);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $assetB->id)->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function google_ads_missing_conversions_are_not_treated_as_zero(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_campaign_performance',
            'payload' => [
                'response_ok' => true,
                'rows' => [[
                    'campaign_id' => '999',
                    'campaign_name' => 'Unknown conversions',
                    'cost' => 80.0,
                    'clicks' => 40.0,
                ]],
            ],
        ]);

        $result = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)
            ->evaluate($asset, [$run->fresh('evidence')]);
        $this->assertTrue($result->evaluationSuccessful);
        $this->assertSame([], $result->matches);
    }

    #[Test]
    public function google_ads_running_or_failed_partial_batches_are_not_synthesized_as_current(): void
    {
        [$asset, $resource, $completedCollection] = $this->makeGoogleAdsAsset();
        $this->insertGoogleAdsCampaignDaily($asset, $resource, $completedCollection->id, '2026-07-23', '111', 10_000_000, 4);
        $this->sealCompletedDailyFacts(
            $asset,
            $resource,
            $completedCollection,
            'google_ads_campaign_daily',
            'GOOGLE_ADS',
            'google_ads_campaign_daily',
            '2026-08-19',
        );

        $runningCollection = $this->makeCollectionRun($asset);
        $running = $this->makeDatasetRun($asset, $runningCollection, 'google_ads_campaign_daily', 'GOOGLE_ADS', CollectionRunStatus::Running, $resource);
        $this->insertGoogleAdsCampaignDaily($asset, $resource, $runningCollection->id, '2026-08-19', '222', 90_000_000, 0, $running->id);

        $runningResult = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertTrue($runningResult->evaluated);
        $this->assertSame('2026-07-23', $runningResult->provenance['period_start']);
        $this->assertSame('2026-08-19', $runningResult->provenance['period_end']);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());
        $this->assertSame(
            ['111'],
            collect(Evidence::query()->where('run_id', $runningResult->run?->id)->first()?->payload['rows'] ?? [])
                ->pluck('campaign_id')
                ->all(),
        );

        $failedCollection = $this->makeCollectionRun($asset);
        $failed = $this->makeDatasetRun($asset, $failedCollection, 'google_ads_campaign_daily', 'GOOGLE_ADS', CollectionRunStatus::Failed, $resource);
        $this->insertGoogleAdsCampaignDaily($asset, $resource, $failedCollection->id, '2026-07-23', '333', 80_000_000, 0, $failed->id);

        $failedResult = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertTrue($failedResult->evaluated);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());
        $this->assertSame(
            ['111'],
            collect(Evidence::query()->where('run_id', $failedResult->run?->id)->first()?->payload['rows'] ?? [])
                ->pluck('campaign_id')
                ->all(),
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function google_ads_incremental_refresh_keeps_older_completed_days_in_the_28_day_window(): void
    {
        [$asset, $resource, $backfillCollection] = $this->makeGoogleAdsAsset();
        $this->insertGoogleAdsCampaignDaily($asset, $resource, $backfillCollection->id, '2026-07-23', '111', 75_000_000, 0);
        $backfillRun = $this->sealCompletedDailyFacts(
            $asset,
            $resource,
            $backfillCollection,
            'google_ads_campaign_daily',
            'GOOGLE_ADS',
            'google_ads_campaign_daily',
            '2026-08-19',
        );

        $incrementalCollection = $this->makeCollectionRun($asset);
        $incrementalRun = $this->makeDatasetRun(
            $asset,
            $incrementalCollection,
            'google_ads_campaign_daily',
            'GOOGLE_ADS',
            CollectionRunStatus::Completed,
            $resource,
        );
        $this->insertGoogleAdsCampaignDaily($asset, $resource, $incrementalCollection->id, '2026-08-19', '555', 5_000_000, 10, $incrementalRun->id);
        DatasetMaterialization::query()
            ->where('dataset_id', 'google_ads_campaign_daily')
            ->where('digital_asset_id', $asset->id)
            ->where('external_resource_id', $resource->id)
            ->update([
                'last_successful_collection_run_id' => $incrementalCollection->id,
                'last_successful_dataset_run_id' => $incrementalRun->id,
            ]);

        $this->assertSame($backfillRun->id, (int) DB::table('google_ads_campaign_daily')
            ->where('campaign_id', '111')
            ->value('last_dataset_run_id'));
        $this->assertSame($incrementalRun->id, (int) DB::table('google_ads_campaign_daily')
            ->where('campaign_id', '555')
            ->value('last_dataset_run_id'));

        $result = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertTrue($result->evaluated);
        $this->assertSame('2026-07-23', $result->provenance['period_start']);
        $this->assertSame('2026-08-19', $result->provenance['period_end']);
        $this->assertContains(GoogleAdsFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS, $result->evaluatedRuleIds);
        $this->assertSame(1, Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', GoogleAdsFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS.':111')
            ->count());
        $campaignIds = collect(Evidence::query()->where('run_id', $result->run?->id)->first()?->payload['rows'] ?? [])
            ->pluck('campaign_id')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['111', '555'], $campaignIds);
        Http::assertNothingSent();
    }

    #[Test]
    public function google_ads_partial_or_gapped_materialization_is_not_synthesized(): void
    {
        [$asset, $resource, $collection] = $this->makeGoogleAdsAsset();
        $this->insertGoogleAdsCampaignDaily($asset, $resource, $collection->id, '2026-07-23', '111', 75_000_000, 0);
        $completed = $this->sealCompletedDailyFacts(
            $asset,
            $resource,
            $collection,
            'google_ads_campaign_daily',
            'GOOGLE_ADS',
            'google_ads_campaign_daily',
            '2026-08-19',
        );

        $dates = $this->inclusiveDateRange('2026-07-23', '2026-08-19');
        $gapped = array_values(array_filter($dates, static fn (string $date): bool => $date !== '2026-08-05'));
        $set = CoverageIntervalSet::fromSuccessfulDates($gapped);
        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', 'google_ads_campaign_daily')
            ->where('digital_asset_id', $asset->id)
            ->where('external_resource_id', $resource->id)
            ->firstOrFail();
        $materialization->forceFill([
            'partial' => true,
            'status' => MaterializationStatus::Partial,
            'coverage_end_date' => '2026-08-19',
            'last_successful_dataset_run_id' => $completed->id,
            'freshness_metadata' => [
                'successful_coverage_dates' => $gapped,
                'internal_gaps' => $set->internalGaps(),
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => '2026-08-19',
            ],
        ])->save();

        $result = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertFalse($result->evaluated);
        $this->assertSame('unusable_google_ads_campaign_daily', $result->skipReason);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());
        $this->assertSame(0, Evidence::query()->where('type', 'google_ads_campaign_performance')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function google_ads_materialization_for_a_running_dataset_run_is_skipped(): void
    {
        [$asset, $resource, $collection] = $this->makeGoogleAdsAsset();
        $running = $this->makeDatasetRun($asset, $collection, 'google_ads_campaign_daily', 'GOOGLE_ADS', CollectionRunStatus::Running, $resource);
        $this->insertGoogleAdsCampaignDaily($asset, $resource, $collection->id, '2026-08-19', '222', 90_000_000, 0, $running->id);
        DatasetMaterialization::query()->create([
            'dataset_id' => 'google_ads_campaign_daily',
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'contract_version' => 1,
            'coverage_start_date' => '2026-08-19',
            'coverage_end_date' => '2026-08-19',
            'last_successful_collection_run_id' => $collection->id,
            'last_successful_dataset_run_id' => $running->id,
            'last_collected_at' => now(),
            'status' => MaterializationStatus::Available,
            'partial' => true,
        ]);

        $result = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertFalse($result->evaluated);
        $this->assertSame('unusable_google_ads_campaign_daily', $result->skipReason);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());
        $this->assertSame(0, Evidence::query()->where('type', 'google_ads_campaign_performance')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function meta_ads_running_partial_batches_are_not_synthesized_as_current(): void
    {
        [$asset, $resource, $completedCollection] = $this->makeMetaAdsAsset();
        $this->insertMetaCampaignDaily($asset, $resource, $completedCollection->id, '2026-07-23', '1001', 5.0);
        $this->insertMetaCampaignSnapshot($asset, $resource, '1001', 'Active', 'ACTIVE', 'ACTIVE');
        $this->sealCompletedDailyFacts(
            $asset,
            $resource,
            $completedCollection,
            'meta_campaign_daily',
            'META_ADS',
            'meta_campaign_daily',
            '2026-08-19',
        );

        $runningCollection = $this->makeCollectionRun($asset);
        $running = $this->makeDatasetRun($asset, $runningCollection, 'meta_campaign_daily', 'META_ADS', CollectionRunStatus::Running, $resource);
        $this->insertMetaCampaignDaily($asset, $resource, $runningCollection->id, '2026-08-19', '2002', 80.0, $running->id);
        $this->insertMetaCampaignSnapshot($asset, $resource, '2002', 'Paused Partial', 'PAUSED', 'CAMPAIGN_PAUSED');

        $result = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertTrue($result->evaluated);
        $this->assertSame('2026-08-19', $result->provenance['period_end']);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function meta_ads_incremental_refresh_keeps_older_completed_days_in_the_28_day_window(): void
    {
        [$asset, $resource, $backfillCollection] = $this->makeMetaAdsAsset();
        $this->insertMetaCampaignDaily($asset, $resource, $backfillCollection->id, '2026-07-23', '1001', 80.0);
        $this->insertMetaCampaignSnapshot($asset, $resource, '1001', 'Paused Lead Form', 'PAUSED', 'CAMPAIGN_PAUSED');
        $backfillRun = $this->sealCompletedDailyFacts(
            $asset,
            $resource,
            $backfillCollection,
            'meta_campaign_daily',
            'META_ADS',
            'meta_campaign_daily',
            '2026-08-19',
        );

        $incrementalCollection = $this->makeCollectionRun($asset);
        $incrementalRun = $this->makeDatasetRun(
            $asset,
            $incrementalCollection,
            'meta_campaign_daily',
            'META_ADS',
            CollectionRunStatus::Completed,
            $resource,
        );
        $this->insertMetaCampaignDaily($asset, $resource, $incrementalCollection->id, '2026-08-19', '3003', 12.0, $incrementalRun->id);
        $this->insertMetaCampaignSnapshot($asset, $resource, '3003', 'Active Later', 'ACTIVE', 'ACTIVE');
        DatasetMaterialization::query()
            ->where('dataset_id', 'meta_campaign_daily')
            ->where('digital_asset_id', $asset->id)
            ->where('external_resource_id', $resource->id)
            ->update([
                'last_successful_collection_run_id' => $incrementalCollection->id,
                'last_successful_dataset_run_id' => $incrementalRun->id,
            ]);

        $this->assertSame($backfillRun->id, (int) DB::table('meta_campaign_daily')
            ->where('campaign_id', '1001')
            ->value('last_dataset_run_id'));

        $result = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertTrue($result->evaluated);
        $this->assertSame('2026-07-23', $result->provenance['period_start']);
        $this->assertSame('2026-08-19', $result->provenance['period_end']);
        $this->assertContains(MetaAdsFindingsCatalog::RULE_CAMPAIGN_INACTIVE_WITH_RECENT_SPEND, $result->evaluatedRuleIds);
        $this->assertSame(1, Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', 'meta-ads:campaign-inactive-with-context:1001')
            ->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function meta_ads_partial_or_gapped_materialization_is_not_synthesized(): void
    {
        [$asset, $resource, $collection] = $this->makeMetaAdsAsset();
        $this->insertMetaCampaignDaily($asset, $resource, $collection->id, '2026-07-23', '1001', 80.0);
        $this->insertMetaCampaignSnapshot($asset, $resource, '1001', 'Paused Lead Form', 'PAUSED', 'CAMPAIGN_PAUSED');
        $completed = $this->sealCompletedDailyFacts(
            $asset,
            $resource,
            $collection,
            'meta_campaign_daily',
            'META_ADS',
            'meta_campaign_daily',
            '2026-08-19',
        );

        $dates = $this->inclusiveDateRange('2026-07-23', '2026-08-19');
        $gapped = array_values(array_filter($dates, static fn (string $date): bool => $date !== '2026-08-05'));
        $set = CoverageIntervalSet::fromSuccessfulDates($gapped);
        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', 'meta_campaign_daily')
            ->where('digital_asset_id', $asset->id)
            ->where('external_resource_id', $resource->id)
            ->firstOrFail();
        $materialization->forceFill([
            'partial' => true,
            'status' => MaterializationStatus::Partial,
            'coverage_end_date' => '2026-08-19',
            'last_successful_dataset_run_id' => $completed->id,
            'freshness_metadata' => [
                'successful_coverage_dates' => $gapped,
                'internal_gaps' => $set->internalGaps(),
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => '2026-08-19',
            ],
        ])->save();

        $result = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertFalse($result->evaluated);
        $this->assertSame('unusable_meta_campaign_daily', $result->skipReason);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function meta_ads_materialization_for_a_failed_dataset_run_is_skipped(): void
    {
        [$asset, $resource, $collection] = $this->makeMetaAdsAsset();
        $failed = $this->makeDatasetRun($asset, $collection, 'meta_campaign_daily', 'META_ADS', CollectionRunStatus::Failed, $resource);
        $this->insertMetaCampaignDaily($asset, $resource, $collection->id, '2026-08-19', '2002', 80.0, $failed->id);
        $this->insertMetaCampaignSnapshot($asset, $resource, '2002', 'Paused Partial', 'PAUSED', 'CAMPAIGN_PAUSED');
        DatasetMaterialization::query()->create([
            'dataset_id' => 'meta_campaign_daily',
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'coverage_start_date' => '2026-08-19',
            'coverage_end_date' => '2026-08-19',
            'last_successful_collection_run_id' => $collection->id,
            'last_successful_dataset_run_id' => $failed->id,
            'last_collected_at' => now(),
            'status' => MaterializationStatus::Partial,
            'partial' => true,
        ]);

        $result = app(CollectedFactsAnalysisService::class)->analyze($asset);
        $this->assertFalse($result->evaluated);
        $this->assertSame('unusable_meta_campaign_daily', $result->skipReason);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function meta_ads_inactive_spend_does_not_evaluate_uncollected_primary_result_rules(): void
    {
        [$assetA, $resourceA, $collectionA] = $this->makeMetaAdsAsset();
        [$assetB, $resourceB] = $this->makeMetaAdsAsset();

        $this->insertMetaCampaignDaily($assetA, $resourceA, $collectionA->id, '2026-08-19', '1001', 80.0);
        $this->insertMetaCampaignSnapshot($assetA, $resourceA, '1001', 'Paused Lead Form', 'PAUSED', 'CAMPAIGN_PAUSED');
        $this->sealCompletedDailyFacts($assetA, $resourceA, $collectionA, 'meta_campaign_daily', 'META_ADS', 'meta_campaign_daily', '2026-08-19');
        $this->insertMetaCampaignDaily($assetB, $resourceB, null, '2026-08-19', '1001', 120.0);
        $this->insertMetaCampaignSnapshot($assetB, $resourceB, '1001', 'Other Brand', 'PAUSED', 'CAMPAIGN_PAUSED');

        $result = app(CollectedFactsAnalysisService::class)->analyze($assetA);
        $this->assertTrue($result->evaluated);
        $this->assertContains(MetaAdsFindingsCatalog::RULE_CAMPAIGN_INACTIVE_WITH_RECENT_SPEND, $result->evaluatedRuleIds);
        $this->assertNotContains(MetaAdsFindingsCatalog::RULE_SPEND_WITHOUT_PRIMARY_RESULT, $result->evaluatedRuleIds);
        $this->assertNotContains(MetaAdsFindingsCatalog::RULE_DELIVERY_WITHOUT_RESOLVED_RESULT, $result->evaluatedRuleIds);

        $this->assertDatabaseHas('findings', [
            'digital_asset_id' => $assetA->id,
            'fingerprint' => 'meta-ads:campaign-inactive-with-context:1001',
            'status' => 'open',
            'source_module' => 'meta-ads',
        ]);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $assetB->id)->count());
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function sibling_provider_facts_never_enter_website_or_ads_analysis(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://atlas.example/',
        ]);
        [$ads, $adsResource, $adsCollection] = $this->makeGoogleAdsAsset($brand);
        [$meta, $metaResource] = $this->makeMetaAdsAsset($brand);

        $this->insertGoogleAdsCampaignDaily($ads, $adsResource, $adsCollection->id, '2026-08-19', '111', 75_000_000, 0);
        $this->sealCompletedDailyFacts($ads, $adsResource, $adsCollection, 'google_ads_campaign_daily', 'GOOGLE_ADS', 'google_ads_campaign_daily', '2026-08-19');
        $this->insertMetaCampaignDaily($meta, $metaResource, null, '2026-08-19', '1001', 80.0);

        $websiteResult = app(CollectedFactsAnalysisService::class)->analyze($website);
        $this->assertFalse($websiteResult->evaluated);
        $this->assertSame(0, Finding::query()->count());

        $adsResult = app(CollectedFactsAnalysisService::class)->analyze($ads);
        $this->assertTrue($adsResult->evaluated);
        $this->assertSame(1, Finding::query()->where('digital_asset_id', $ads->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $meta->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $website->id)->count());
    }

    #[Test]
    public function cross_asset_pack_stays_inside_the_same_brand(): void
    {
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $websiteA = DigitalAsset::factory()->create(['brand_id' => $brandA->id, 'type' => 'website']);
        $websiteB = DigitalAsset::factory()->create(['brand_id' => $brandB->id, 'type' => 'website']);
        $adsA = DigitalAsset::factory()->create(['brand_id' => $brandA->id, 'type' => 'google_ads']);
        DigitalAsset::factory()->create(['brand_id' => $brandB->id, 'type' => 'google_ads']);

        $this->seedWebsiteHttp($websiteA, 'https://www.acme.example/');
        $this->seedWebsiteHttp($websiteB, 'https://www.acme.example/');
        $this->seedAdsLanding($adsA, ['https://other-landing.example/campaign']);

        $run = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($websiteB);
        $this->assertSame('missing_google_ads_landing_final_urls_evidence', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $websiteB->id)->count());

        $compared = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($websiteA);
        $this->assertTrue($compared->metadata['compared'] ?? false);
        $this->assertSame(1, Finding::query()->where('digital_asset_id', $websiteA->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $websiteB->id)->count());
    }

    #[Test]
    public function evaluate_findings_job_runs_collected_facts_adapters(): void
    {
        config(['moxdop-opportunity-rules.evaluate_after_findings' => false]);
        $website = $this->makeWebsiteAsset('https://atlas.example/');
        $collectionRun = $this->makeCollectionRun($website);
        $this->insertWebsiteMetadata($website, $collectionRun->id, '2026-08-20 09:00:00', [
            'title' => null,
            'title_present' => false,
            'meta_description' => 'Atlas Dental clinic in Istanbul offers implant and smile design services for visiting patients.',
            'meta_description_present' => true,
        ]);

        (new EvaluateFindingsForAssetJob($website->id))->handle(
            app(FindingEvaluationService::class),
            app(CollectedFactsAnalysisService::class),
        );

        $this->assertSame(1, Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->count());
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function canonical_finding_evaluation_emits_outcome_v1_on_later_clear(): void
    {
        $this->travelTo('2026-08-20 10:00:00');
        $website = $this->makeWebsiteAsset('https://atlas.example/');
        $evidence = $this->writeCanonicalGsc($website, 'ev-decline', 200, 50);

        app(FindingEvaluationService::class)->evaluateAsset($website, ruleIds: ['website:gsc:clicks-decline']);
        $finding = Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', 'website:gsc:clicks-decline')
            ->firstOrFail();
        $this->assertSame('website', $finding->source_module);
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());

        $recommendation = app(CreateRecommendationFromFinding::class)->create(
            $finding,
            [
                'title' => 'Investigate Search Console click decline',
                'action' => 'Review cited Search Console Evidence before changing the site.',
            ],
            RecommendationOrigin::DeterministicTemplate,
            $this->admin,
        );
        $task = app(CreateTaskFromRecommendation::class)->create($recommendation);
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);

        $task = app(TaskLifecycleService::class)->complete($task, [
            'completion_note' => 'Published content outside MoxDOP.',
        ], $this->admin);
        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->outcome_status);
        $this->assertFalse(data_get($task->outcome_json, 'causal_attribution'));

        $this->travelTo('2026-08-20 11:00:00');
        $evidence->forceFill([
            'payload' => $this->gscPeriodPayload(200, 190),
        ])->save();
        app(FindingEvaluationService::class)->evaluateAsset($website, ruleIds: ['website:gsc:clicks-decline']);

        $this->assertSame('resolved', $finding->fresh()->status);
        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
        $this->assertFalse(data_get($task->fresh()->outcome_json, 'causal_attribution'));
        Http::assertNothingSent();
    }

    private function agencyIntegration(string $provider): CoreIntegration
    {
        return CoreIntegration::query()->firstOrCreate(
            ['provider' => $provider],
            [
                'name' => ProviderRegistry::defaultName($provider),
                'status' => CoreIntegration::STATUS_ACTIVE,
                'config' => [],
            ],
        );
    }

    private function makeWebsiteAsset(string $url): DigitalAsset
    {
        $brand = Brand::factory()->create();

        return DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => $url,
        ]);
    }

    /**
     * @return array{0: DigitalAsset, 1: CoreExternalResource, 2?: CollectionRun}
     */
    private function makeGoogleAdsAsset(?Brand $brand = null): array
    {
        $brand ??= Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
        ]);
        $integration = $this->agencyIntegration(ProviderRegistry::GOOGLE);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => (string) fake()->unique()->numerify('##########'),
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource, $this->makeCollectionRun($asset)];
    }

    /**
     * @return array{0: DigitalAsset, 1: CoreExternalResource, 2?: CollectionRun}
     */
    private function makeMetaAdsAsset(?Brand $brand = null): array
    {
        $brand ??= Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
        ]);
        $integration = $this->agencyIntegration(ProviderRegistry::META);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'meta',
            'resource_type' => 'meta_ads',
            'external_id' => 'act_'.fake()->unique()->numerify('############'),
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource, $this->makeCollectionRun($asset)];
    }

    private function makeCollectionRun(DigitalAsset $asset): CollectionRun
    {
        return CollectionRun::factory()->create([
            'customer_id' => $asset->brand?->customer_id,
            'brand_id' => $asset->brand_id,
            'digital_asset_id' => $asset->id,
        ]);
    }

    private function makeDatasetRun(
        DigitalAsset $asset,
        CollectionRun $collectionRun,
        string $datasetId,
        string $provider,
        CollectionRunStatus $status,
        ?CoreExternalResource $resource = null,
    ): CollectionDatasetRun {
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $collectionRun->id,
            'provider_or_source' => $provider,
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource?->id,
            'status' => $status,
        ]);

        return CollectionDatasetRun::factory()->create([
            'collection_run_id' => $collectionRun->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => $provider,
            'dataset_contract_id' => $datasetId,
            'status' => $status,
        ]);
    }

    private function sealCompletedDailyFacts(
        DigitalAsset $asset,
        CoreExternalResource $resource,
        CollectionRun $collectionRun,
        string $datasetId,
        string $provider,
        string $table,
        string $coverageDate,
        int $periodDays = 28,
    ): CollectionDatasetRun {
        $datasetRun = $this->makeDatasetRun($asset, $collectionRun, $datasetId, $provider, CollectionRunStatus::Completed, $resource);
        $periodStart = CarbonImmutable::parse($coverageDate)->subDays($periodDays - 1)->toDateString();
        $dates = $this->inclusiveDateRange($periodStart, $coverageDate);
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);
        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'provider_or_source' => $provider,
            'contract_version' => 1,
            'coverage_start_date' => $periodStart,
            'coverage_end_date' => $coverageDate,
            'last_successful_collection_run_id' => $collectionRun->id,
            'last_successful_dataset_run_id' => $datasetRun->id,
            'last_collected_at' => now(),
            'status' => MaterializationStatus::Available,
            'partial' => false,
            'freshness_metadata' => [
                'successful_coverage_dates' => $dates,
                'internal_gaps' => [],
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => $coverageDate,
                'watermark_provenance' => 'successful_coverage_dates',
            ],
        ]);
        DB::table($table)
            ->where('digital_asset_id', $asset->id)
            ->where('external_resource_id', $resource->id)
            ->whereNull('last_dataset_run_id')
            ->update(['last_dataset_run_id' => $datasetRun->id]);

        return $datasetRun;
    }

    /**
     * @return list<string>
     */
    private function inclusiveDateRange(string $start, string $end): array
    {
        $dates = [];
        $cursor = CarbonImmutable::parse($start)->startOfDay();
        $last = CarbonImmutable::parse($end)->startOfDay();
        while ($cursor->lessThanOrEqualTo($last)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function insertWebsiteMetadata(DigitalAsset $asset, ?int $collectionRunId, string $observedAt, array $metadata): void
    {
        DB::table('website_metadata_snapshot')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => null,
            'url' => (string) $asset->primary_url,
            'observed_at' => $observedAt,
            'contract_version' => 1,
            'last_collection_run_id' => $collectionRunId,
            'last_dataset_run_id' => null,
            'first_collected_at' => $observedAt,
            'last_collected_at' => $observedAt,
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', $asset->id.'|'.$observedAt.'|'.json_encode($metadata)),
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertGoogleAdsCampaignDaily(
        DigitalAsset $asset,
        CoreExternalResource $resource,
        ?int $collectionRunId,
        string $date,
        string $campaignId,
        int $costMicros,
        int $conversions,
        ?int $datasetRunId = null,
    ): void {
        DB::table('google_ads_campaign_daily')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'customer_id' => $resource->external_id,
            'reporting_date' => $date,
            'campaign_id' => $campaignId,
            'impressions' => 800,
            'clicks' => 40,
            'cost_micros' => $costMicros,
            'cost_amount' => $costMicros / 1_000_000,
            'conversions' => $conversions,
            'currency' => 'EUR',
            'contract_version' => 1,
            'last_collection_run_id' => $collectionRunId,
            'last_dataset_run_id' => $datasetRunId,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', $asset->id.'|'.$campaignId.'|'.$date.'|'.($datasetRunId ?? 'none')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMetaCampaignDaily(
        DigitalAsset $asset,
        CoreExternalResource $resource,
        ?int $collectionRunId,
        string $date,
        string $campaignId,
        float $spend,
        ?int $datasetRunId = null,
    ): void {
        DB::table('meta_campaign_daily')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'account_id' => $resource->external_id,
            'reporting_date' => $date,
            'campaign_id' => $campaignId,
            'spend' => $spend,
            'impressions' => 900,
            'clicks' => 30,
            'currency' => 'EUR',
            'contract_version' => 1,
            'last_collection_run_id' => $collectionRunId,
            'last_dataset_run_id' => $datasetRunId,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', $asset->id.'|meta|'.$campaignId.'|'.$date.'|'.($datasetRunId ?? 'none')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMetaCampaignSnapshot(
        DigitalAsset $asset,
        CoreExternalResource $resource,
        string $campaignId,
        string $name,
        string $status,
        string $effectiveStatus,
    ): void {
        DB::table('meta_campaign_snapshot')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'account_id' => $resource->external_id,
            'campaign_id' => $campaignId,
            'contract_version' => 1,
            'last_collection_run_id' => null,
            'last_dataset_run_id' => null,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', $asset->id.'|snap|'.$campaignId),
            'metadata' => json_encode([
                'name' => $name,
                'status' => $status,
                'effective_status' => $effectiveStatus,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedWebsiteHttp(DigitalAsset $asset, string $url): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'type' => 'http_fetch',
            'payload' => [
                'url' => $url,
                'effective_url' => $url,
                'response_is_ok' => true,
            ],
            'observed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $urls
     */
    private function seedAdsLanding(DigitalAsset $asset, array $urls): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => GoogleAdsLandingFinalUrlsCollectService::EVIDENCE_TYPE_LANDING_FINAL_URLS,
            'payload' => [
                'final_urls' => $urls,
                'ok' => true,
            ],
            'observed_at' => now(),
        ]);
    }

    private function writeCanonicalGsc(DigitalAsset $asset, string $fingerprint, int $clicksPrev, int $clicksCurrent): Evidence
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'evidence-canonicalization',
            'status' => 'completed',
        ]);

        return Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'search-console',
            'type' => 'gsc.property.period_comparison',
            'definition_id' => 'gsc.property.period_comparison',
            'evidence_fingerprint' => $fingerprint.'-'.$asset->id,
            'is_canonical' => true,
            'eligibility_status' => 'eligible',
            'title' => 'gsc.property.period_comparison',
            'generated_by_ai' => false,
            'payload' => $this->gscPeriodPayload($clicksPrev, $clicksCurrent),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscPeriodPayload(int $clicksPrev, int $clicksCurrent): array
    {
        $relative = $clicksPrev === 0 ? null : ($clicksCurrent - $clicksPrev) / $clicksPrev;

        return [
            'definition_id' => 'gsc.property.period_comparison',
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
            'period' => [
                'current' => ['start' => '2026-07-16', 'end' => '2026-08-12'],
                'previous' => ['start' => '2026-06-18', 'end' => '2026-07-15'],
            ],
            'metrics' => [
                'clicks' => [
                    'current' => $clicksCurrent,
                    'previous' => $clicksPrev,
                    'relative_change' => $relative,
                    'relative_change_state' => 'VALUE',
                ],
                'impressions' => [
                    'current' => 90,
                    'previous' => 100,
                    'relative_change' => -0.1,
                    'relative_change_state' => 'VALUE',
                ],
                'ctr' => [
                    'current' => 0.19,
                    'previous' => 0.20,
                    'current_state' => 'VALUE',
                    'previous_state' => 'VALUE',
                ],
            ],
        ];
    }
}
