<?php

namespace Tests\Feature\Analysis;

use App\Enums\RecommendationOrigin;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
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
use App\Services\GoogleAdsLandingFinalUrlsCollectService;
use App\Services\Tasks\TaskLifecycleService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use App\Support\Tasks\TaskOutcomeStatus;
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
    public function meta_ads_inactive_spend_does_not_evaluate_uncollected_primary_result_rules(): void
    {
        [$assetA, $resourceA, $collectionA] = $this->makeMetaAdsAsset();
        [$assetB, $resourceB] = $this->makeMetaAdsAsset();

        $this->insertMetaCampaignDaily($assetA, $resourceA, $collectionA->id, '2026-08-19', '1001', 80.0);
        $this->insertMetaCampaignSnapshot($assetA, $resourceA, '1001', 'Paused Lead Form', 'PAUSED', 'CAMPAIGN_PAUSED');
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
        [$ads, $adsResource] = $this->makeGoogleAdsAsset($brand);
        [$meta, $metaResource] = $this->makeMetaAdsAsset($brand);

        $this->insertGoogleAdsCampaignDaily($ads, $adsResource, null, '2026-08-19', '111', 75_000_000, 0);
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
            'last_dataset_run_id' => null,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', $asset->id.'|'.$campaignId.'|'.$date),
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
            'last_dataset_run_id' => null,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', $asset->id.'|meta|'.$campaignId.'|'.$date),
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
}
