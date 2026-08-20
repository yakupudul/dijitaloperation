<?php

namespace Tests\Feature\Analysis;

use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Enums\RecommendationOrigin;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\CreateTaskFromRecommendation;
use App\Services\CrossAssetWebsiteGoogleAdsLandingConsistencyService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\Evidence\CanonicalEvidencePipeline;
use App\Services\Findings\FindingEvaluationService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\Recommendations\CreateRecommendationFromFinding;
use App\Services\Tasks\TaskLifecycleService;
use App\Support\Evidence\EvidencePeriod;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use App\Support\Tasks\TaskOutcomeStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MoxDop\MetaAds\Findings\MetaAdsFindingsCatalog;
use MoxDop\MetaAds\Findings\MetaAdsNormalizedFactsEvaluator;
use MoxDop\Website\Ai\WebsiteAiRecommendationService;
use Tests\TestCase;

class AgencyBrainOperationalSynthesisC1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config([
            'moxdop-intelligence-scheduling.enabled' => false,
            'moxdop-finding-rules.evaluate_after_canonicalization' => false,
            'moxdop-opportunity-rules.evaluate_after_findings' => false,
        ]);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_website_seo_gsc_pool_facts_create_idempotent_finding_without_demo_or_http(): void
    {
        [$asset] = $this->seedGscAsset();
        $period = $this->analysisPeriod();
        $this->seedDailyReady($asset, 'gsc_property_daily', 'SEARCH_CONSOLE', $this->allDates(), function (string $date) use ($asset): void {
            $this->insertGscDaily($asset, $date, $this->isCurrent($date) ? 2 : 10, 10);
        });

        $pipeline = app(CanonicalEvidencePipeline::class);
        $firstEvidence = $pipeline->canonicalizeAsset($asset, period: $period, definitionIds: ['gsc.property.period_comparison']);
        $this->assertSame(1, $firstEvidence->created, json_encode($firstEvidence->ineligible));
        $this->assertSame($asset->id, $firstEvidence->written[0]->digital_asset_id);
        $this->assertSame($this->resourceId($asset), $firstEvidence->written[0]->payload['provenance']['external_resource_id']);
        $this->assertFalse($firstEvidence->written[0]->generated_by_ai);

        $first = app(FindingEvaluationService::class)->evaluateAsset($asset, ruleIds: ['website:gsc:clicks-decline']);
        $this->assertSame(1, $first->findingsCreated);
        $finding = Finding::query()->where('digital_asset_id', $asset->id)->firstOrFail();
        $this->assertSame('website:gsc:clicks-decline', $finding->fingerprint);
        $this->assertSame('open', $finding->status);
        $this->assertSame($asset->id, $finding->digital_asset_id);
        $this->assertSame($asset->brand_id, $finding->brand_id);

        $second = app(FindingEvaluationService::class)->evaluateAsset($asset, ruleIds: ['website:gsc:clicks-decline']);
        $this->assertSame(0, $second->findingsCreated);
        $this->assertSame(1, Finding::query()->where('digital_asset_id', $asset->id)->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
    }

    public function test_google_ads_pool_facts_create_finding_and_manual_task_then_outcome_improves(): void
    {
        [$asset] = $this->seedGoogleAdsAsset();
        $period = $this->analysisPeriod();
        $this->seedDailyReady($asset, 'google_ads_account_daily', 'GOOGLE_ADS', $this->allDates(), function (string $date) use ($asset): void {
            $this->insertAdsDaily($asset, $date, $this->isCurrent($date) ? 0 : 2);
        });

        app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $asset,
            period: $period,
            definitionIds: ['google_ads.account.period_comparison'],
        );

        $stats = app(FindingEvaluationService::class)->evaluateAsset($asset, ruleIds: ['google-ads:conversions-decline']);
        $this->assertSame(1, $stats->findingsCreated);
        $finding = Finding::query()->where('digital_asset_id', $asset->id)->firstOrFail();
        $this->assertSame('google-ads:conversions-decline', $finding->fingerprint);
        $this->assertSame('google-ads', $finding->source_module);
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());

        $recommendation = app(CreateRecommendationFromFinding::class)->create(
            $finding,
            [
                'title' => 'Investigate Google Ads conversion decline',
                'action' => 'Review conversion tracking continuity using cited Ads Evidence.',
            ],
            RecommendationOrigin::DeterministicTemplate,
            $this->admin,
        );
        $this->assertSame($finding->id, $recommendation->finding_id);

        $task = app(CreateTaskFromRecommendation::class)->create($recommendation, [], $this->admin);
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
        $this->assertSame($asset->id, $task->digital_asset_id);
        $this->assertSame($finding->fingerprint, $task->snapshot_json['finding']['fingerprint'] ?? null);

        $task = app(TaskLifecycleService::class)->complete($task, [], $this->admin);
        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->outcome_status);
        $this->assertFalse(data_get($task->outcome_json, 'causal_attribution'));
        $this->assertSame('open', $finding->fresh()->status);

        Carbon::setTestNow(CarbonImmutable::parse('2026-08-20 12:05:00', 'UTC'));
        foreach ($this->currentDates() as $date) {
            DB::table('google_ads_account_daily')
                ->where('digital_asset_id', $asset->id)
                ->where('reporting_date', $date)
                ->update(['conversions' => 2, 'updated_at' => now()]);
        }
        app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $asset,
            period: $period,
            definitionIds: ['google_ads.account.period_comparison'],
        );
        app(FindingEvaluationService::class)->evaluateAsset($asset, ruleIds: ['google-ads:conversions-decline']);

        $this->assertSame('resolved', $finding->fresh()->status);
        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
        $this->assertFalse(data_get($task->fresh()->outcome_json, 'causal_attribution'));
        Http::assertNothingSent();
    }

    public function test_missing_google_ads_facts_do_not_become_zero_findings(): void
    {
        [$asset] = $this->seedGoogleAdsAsset();
        $period = $this->analysisPeriod();
        app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $asset,
            period: $period,
            definitionIds: ['google_ads.account.period_comparison'],
        );
        $stats = app(FindingEvaluationService::class)->evaluateAsset($asset, ruleIds: ['google-ads:conversions-decline']);
        $this->assertSame(0, Finding::query()->count());
        $this->assertGreaterThan(0, $stats->rulesBlocked);
        Http::assertNothingSent();
    }

    public function test_same_customer_multi_brand_and_sibling_providers_do_not_leak(): void
    {
        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id]);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id]);
        [$adsA] = $this->seedGoogleAdsAsset($brandA);
        [$metaA] = $this->seedMetaAdsAsset($brandA);
        [$adsB] = $this->seedGoogleAdsAsset($brandB);
        $period = $this->analysisPeriod();

        $this->seedDailyReady($adsA, 'google_ads_account_daily', 'GOOGLE_ADS', $this->allDates(), function (string $date) use ($adsA): void {
            $this->insertAdsDaily($adsA, $date, $this->isCurrent($date) ? 0 : 2);
        });

        app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $adsA,
            period: $period,
            definitionIds: ['google_ads.account.period_comparison'],
        );
        app(FindingEvaluationService::class)->evaluateAsset($adsA, ruleIds: ['google-ads:conversions-decline']);
        app(FindingEvaluationService::class)->evaluateAsset($adsB, ruleIds: ['google-ads:conversions-decline']);
        app(FindingEvaluationService::class)->evaluateAsset($metaA, ruleIds: ['google-ads:conversions-decline']);

        $this->assertSame(1, Finding::query()->where('digital_asset_id', $adsA->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $adsB->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $metaA->id)->count());
        $this->assertSame($brandA->id, Finding::query()->value('brand_id'));
    }

    public function test_meta_normalized_facts_inactive_campaign_finding_is_idempotent_and_skips_when_uncollected(): void
    {
        [$asset] = $this->seedMetaAdsAsset();
        $this->seedDailyReady($asset, 'meta_campaign_daily', 'META_ADS', $this->allDates(), function (string $date) use ($asset): void {
            $this->insertMetaCampaignDaily($asset, $date, 'camp-1', 80.0, 800, 40);
        });
        $this->seedSnapshotReady($asset, 'meta_campaign_snapshot', 'META_ADS');
        $this->insertMetaCampaignSnapshot($asset, 'camp-1', 'PAUSED', 'Lead Campaign');

        $first = app(MetaAdsNormalizedFactsEvaluator::class)->evaluateAndApply($asset);
        $this->assertNotNull($first);
        $this->assertSame(1, Finding::query()->where('digital_asset_id', $asset->id)->count());
        $finding = Finding::query()->firstOrFail();
        $this->assertSame(MetaAdsFindingsCatalog::RULE_CAMPAIGN_INACTIVE_WITH_RECENT_SPEND.':camp-1', $finding->fingerprint);
        $this->assertSame($asset->id, $finding->digital_asset_id);
        $this->assertSame(0, Task::query()->count());

        $second = app(MetaAdsNormalizedFactsEvaluator::class)->evaluateAndApply($asset);
        $this->assertSame(1, Finding::query()->where('digital_asset_id', $asset->id)->count());
        $this->assertSame($finding->id, Finding::query()->value('id'));
        $this->assertGreaterThan(0, $second['updated'] + $second['opened']);

        [$emptyAsset] = $this->seedMetaAdsAsset();
        $this->assertNull(app(MetaAdsNormalizedFactsEvaluator::class)->evaluateAndApply($emptyAsset));
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $emptyAsset->id)->count());
        Http::assertNothingSent();
    }

    public function test_cross_asset_pack_stays_on_same_brand_and_skips_without_inventing_scores(): void
    {
        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id]);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brandA->id,
            'type' => 'website',
            'primary_url' => 'https://acme.example',
        ]);
        DigitalAsset::factory()->create([
            'brand_id' => $brandB->id,
            'type' => 'google_ads',
        ]);

        $run = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($website);
        $this->assertSame('missing_google_ads_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertFalse($run->metadata['compared'] ?? true);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_website_ai_does_not_fallback_when_deterministic_findings_are_absent(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one Finding');
        app(WebsiteAiRecommendationService::class)->analyze($asset);
    }

    public function test_learning_candidates_cannot_auto_promote(): void
    {
        $this->assertFalse(class_exists('App\\Models\\LearningCandidate'));
        $this->assertFalse(class_exists('App\\Models\\AgencyKnowledge'));
        $this->assertFalse(class_exists('App\\Services\\Learning\\AutoPromoteLearningCandidate'));
        $this->assertFalse(class_exists('App\\Services\\AgencyKnowledge\\AutoPromoteService'));
    }

    /**
     * @return array{0: DigitalAsset, 1: CoreExternalResource}
     */
    private function seedGscAsset(?Brand $brand = null): array
    {
        $brand ??= Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'gsc',
            'module_id' => 'search_console',
            'status' => DigitalAssetStatus::Active,
        ]);
        $resource = $this->bindGoogle(
            $asset,
            GoogleResourceType::GSC_PROPERTY,
            GscSpecialistBindingResolver::CAPABILITY,
            'sc-domain:example-'.$asset->id.'.com',
        );

        return [$asset, $resource];
    }

    /**
     * @return array{0: DigitalAsset, 1: CoreExternalResource}
     */
    private function seedGoogleAdsAsset(?Brand $brand = null): array
    {
        $brand ??= Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $resource = $this->bindGoogle(
            $asset,
            GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            GoogleAdsSpecialistBindingResolver::CAPABILITY,
            (string) (1_000_000_000 + $asset->id),
        );

        return [$asset, $resource];
    }

    /**
     * @return array{0: DigitalAsset, 1: CoreExternalResource}
     */
    private function seedMetaAdsAsset(?Brand $brand = null): array
    {
        $brand ??= Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $integration = $this->agencyIntegration(ProviderRegistry::META);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_'.(string) $asset->id,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['reporting_timezone' => 'UTC'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource];
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

    private function bindGoogle(DigitalAsset $asset, string $resourceType, string $capability, string $externalId): CoreExternalResource
    {
        $integration = $this->agencyIntegration(ProviderRegistry::GOOGLE);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => $resourceType,
            'external_id' => $externalId,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['reporting_timezone' => 'UTC'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => $capability,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return $resource;
    }

    /**
     * @param  list<string>  $dates
     * @param  callable(string): void  $insertRow
     */
    private function seedDailyReady(
        DigitalAsset $asset,
        string $datasetId,
        string $provider,
        array $dates,
        callable $insertRow,
    ): void {
        $this->seedMaterialization($asset, $datasetId, $provider, $dates);
        foreach ($dates as $date) {
            $insertRow($date);
        }
    }

    /**
     * @param  list<string>  $dates
     */
    private function seedMaterialization(DigitalAsset $asset, string $datasetId, string $provider, array $dates = []): void
    {
        $resourceId = $this->resourceId($asset);
        $dates = array_values(array_unique($dates));
        sort($dates);
        $set = $dates === [] ? null : CoverageIntervalSet::fromSuccessfulDates($dates);

        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resourceId,
            'provider_or_source' => $provider,
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => now(),
            'coverage_start_date' => $set?->bounds()['start'],
            'coverage_end_date' => $set?->bounds()['end'] ?? '2026-08-19',
            'row_count_approx' => count($dates),
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => false,
            'freshness_metadata' => $set === null ? [] : [
                'successful_coverage_dates' => $dates,
                'coverage_intervals' => $set->intervals,
                'internal_gaps' => $set->internalGaps(),
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => $set->bounds()['end'],
                'last_successful_reporting_date' => $set->verifiedContiguousWatermark(),
                'last_reprocess_through' => $set->verifiedContiguousWatermark(),
            ],
        ]);

        $audit = DataIntegrityAuditRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => IntegrityAuditStatus::Completed,
            'mode' => IntegrityAuditMode::LocalIntegrity,
            'scope_type' => 'dataset',
            'scope' => ['dataset_id' => $datasetId],
            'contract_registry_version' => 1,
            'storage_contract_version' => 1,
            'formula_registry_version' => 1,
            'integrity_registry_version' => 1,
            'audit_rules_version' => 1,
            'started_at' => now(),
            'completed_at' => now(),
            'checks_total' => 1,
            'checks_pass' => 1,
            'checks_fail' => 0,
        ]);

        DataIntegrityCheckResult::query()->create([
            'audit_run_id' => $audit->id,
            'provider_or_source' => $provider,
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resourceId,
            'dataset_id' => $datasetId,
            'check_id' => 'natural_key_uniqueness',
            'category' => 'integrity',
            'severity' => 'info',
            'status' => IntegrityCheckStatus::Pass,
            'message' => 'test check',
            'blocks_migration' => false,
        ]);
    }

    private function seedSnapshotReady(DigitalAsset $asset, string $datasetId, string $provider): void
    {
        $this->seedMaterialization($asset, $datasetId, $provider, []);
    }

    private function insertGscDaily(DigitalAsset $asset, string $date, int $clicks, int $impressions): void
    {
        DB::table('gsc_property_daily')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $this->resourceId($asset),
            'site_url' => (string) CoreExternalResource::query()->findOrFail($this->resourceId($asset))->external_id,
            'reporting_date' => $date,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'gsc-'.$asset->id.'-'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAdsDaily(DigitalAsset $asset, string $date, int $conversions): void
    {
        DB::table('google_ads_account_daily')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $this->resourceId($asset),
            'customer_id' => (string) CoreExternalResource::query()->findOrFail($this->resourceId($asset))->external_id,
            'reporting_date' => $date,
            'impressions' => 1000,
            'clicks' => 100,
            'cost_micros' => 5_000_000,
            'cost_amount' => 5,
            'conversions' => $conversions,
            'currency' => 'GBP',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'ads-'.$asset->id.'-'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMetaCampaignDaily(
        DigitalAsset $asset,
        string $date,
        string $campaignId,
        float $spend,
        int $impressions,
        int $clicks,
    ): void {
        DB::table('meta_campaign_daily')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $this->resourceId($asset),
            'account_id' => 'act_'.$asset->id,
            'reporting_date' => $date,
            'campaign_id' => $campaignId,
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'currency' => 'EUR',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'meta-'.$asset->id.'-'.$campaignId.'-'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMetaCampaignSnapshot(DigitalAsset $asset, string $campaignId, string $status, string $name): void
    {
        DB::table('meta_campaign_snapshot')->insert([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $this->resourceId($asset),
            'account_id' => 'act_'.$asset->id,
            'campaign_id' => $campaignId,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'meta-snap-'.$asset->id.'-'.$campaignId),
            'metadata' => json_encode([
                'name' => $name,
                'objective' => 'OUTCOME_LEADS',
                'status' => $status,
                'effective_status' => $status,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resourceId(DigitalAsset $asset): int
    {
        return (int) CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->value('external_resource_id');
    }

    private function analysisPeriod(): EvidencePeriod
    {
        return new EvidencePeriod('2026-07-23', '2026-08-19', '2026-06-25', '2026-07-22', 28);
    }

    /**
     * @return list<string>
     */
    private function allDates(): array
    {
        return [...$this->previousDates(), ...$this->currentDates()];
    }

    /**
     * @return list<string>
     */
    private function previousDates(): array
    {
        return $this->contiguousDates('2026-06-25', 28);
    }

    /**
     * @return list<string>
     */
    private function currentDates(): array
    {
        return $this->contiguousDates('2026-07-23', 28);
    }

    private function isCurrent(string $date): bool
    {
        return $date >= '2026-07-23';
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
}
