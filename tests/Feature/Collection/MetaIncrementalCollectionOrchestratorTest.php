<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Meta\MetaIncrementalCollectionOrchestrator;
use App\Services\Collection\Meta\MetaInitialBackfillOrchestrator;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use App\Services\Collection\Support\CollectionClock;
use App\Services\DataPool\Freshness\CollectableEndResolver;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\DataPool\Freshness\DatasetWatermarkCalculator;
use App\Services\DataPool\Freshness\IncrementalCoveragePlanner;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaIncrementalCollectionOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN_AT = '2026-08-13 15:00:00';

    private User $admin;

    private CoreIntegration $integration;

    private DigitalAsset $assetA;

    private DigitalAsset $assetB;

    private CoreAssetBinding $bindingA;

    private CoreAssetBinding $bindingB;

    private CoreExternalResource $resourceA;

    private CoreExternalResource $resourceB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-collection.queue' => 'collection',
            'moxdop.meta.access_token' => '',
        ]);

        $clock = new CollectionClock(CarbonImmutable::parse(self::FROZEN_AT, 'UTC'));
        $this->app->instance(CollectionClock::class, $clock);
        $collectableEnd = new CollectableEndResolver($clock);
        $watermarks = new DatasetWatermarkCalculator($collectableEnd);
        $evaluator = new DatasetFreshnessEvaluator($watermarks, $clock);
        $planner = new IncrementalCoveragePlanner(
            app(DataFreshnessPolicyLoader::class),
            $evaluator,
            $watermarks,
        );
        $this->app->instance(CollectableEndResolver::class, $collectableEnd);
        $this->app->instance(DatasetWatermarkCalculator::class, $watermarks);
        $this->app->instance(DatasetFreshnessEvaluator::class, $evaluator);
        $this->app->instance(IncrementalCoveragePlanner::class, $planner);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Home Brand']);

        $this->assetA = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $this->assetB = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_method' => 'oauth',
                'auth_status' => 'connected',
                'connection_status' => 'connected',
                'credential_status' => 'valid',
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'EAAG-synthetic-meta-token-never-real',
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);

        $this->resourceA = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_11110001',
            'display_name' => 'Home Ads A',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
            ],
        ]);
        $this->resourceB = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_22220002',
            'display_name' => 'Home Ads B',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
            ],
        ]);

        $this->bindingA = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->assetA->id,
            'external_resource_id' => $this->resourceA->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->bindingB = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->assetB->id,
            'external_resource_id' => $this->resourceB->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function sibling_ad_account_due_work_starts_instead_of_data_current_and_excludes_google_bindings(): void
    {
        $sameCustomerOtherBrand = $this->createSameCustomerOtherBrandBinding();
        $otherCustomer = $this->createOtherCustomerBindingOnSameIntegration();
        $googleBinding = $this->createGoogleAdsBinding();

        $this->materializeBindingFresh($this->bindingA, staleDatasetIds: []);
        $this->materializeBindingFresh($this->bindingB, staleDatasetIds: ['meta_campaign_daily']);
        $this->materializeBindingFresh($sameCustomerOtherBrand, staleDatasetIds: []);
        $this->materializeBindingFresh($otherCustomer, staleDatasetIds: []);

        $preflight = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertContains($this->bindingA->id, $preflight->eligibleBindingIds);
        $this->assertContains($this->bindingB->id, $preflight->eligibleBindingIds);
        $this->assertContains($sameCustomerOtherBrand->id, $preflight->eligibleBindingIds);
        $this->assertContains($otherCustomer->id, $preflight->eligibleBindingIds);
        $this->assertNotContains($googleBinding->id, $preflight->eligibleBindingIds);

        $result = app(MetaIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $this->assertNotNull($result->collectionRun);
        $this->assertSame(CollectionTriggerType::Incremental, $result->collectionRun->trigger_type);
        $this->assertSame('Incremental Meta Ads Refresh', $result->collectionRun->metadata['collection_intent_label'] ?? null);

        $plannedBindingIds = $result->collectionRun->resourceRuns()->pluck('core_asset_binding_id')->all();
        $this->assertContains($this->bindingB->id, $plannedBindingIds);
        $this->assertNotContains($this->bindingA->id, $plannedBindingIds);
        $this->assertNotContains($sameCustomerOtherBrand->id, $plannedBindingIds);
        $this->assertNotContains($otherCustomer->id, $plannedBindingIds);
        $this->assertNotContains($googleBinding->id, $plannedBindingIds);

        $decisionBindingIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['core_asset_binding_id'],
            $result->decisions,
        )));
        $this->assertContains($this->bindingB->id, $decisionBindingIds);
        $this->assertNotContains($googleBinding->id, $decisionBindingIds);

        $queuedFamilies = $result->collectionRun->datasetRuns()
            ->where('status', CollectionRunStatus::Queued)
            ->pluck('request_family_id')
            ->all();
        $this->assertContains(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY, $queuedFamilies);
        $this->assertTrue(collect($queuedFamilies)->every(
            static fn (string $familyId): bool => str_starts_with($familyId, 'RF_META_'),
        ));

        Http::assertNothingSent();
    }

    #[Test]
    public function data_current_only_when_every_eligible_meta_dataset_in_preflight_scope_is_current(): void
    {
        $googleBinding = $this->createGoogleAdsBinding();
        $this->materializeBindingFresh($this->bindingA, staleDatasetIds: []);
        $this->materializeBindingFresh($this->bindingB, staleDatasetIds: []);

        $result = app(MetaIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);

        $this->assertSame('data_current', $result->outcome);
        $this->assertNull($result->collectionRun);
        $this->assertSame(0, CollectionRun::query()->where('trigger_type', CollectionTriggerType::Incremental)->count());
        $this->assertNotContains($googleBinding->id, array_column($result->decisions, 'core_asset_binding_id'));
        Http::assertNothingSent();
    }

    #[Test]
    public function same_customer_multi_brand_due_work_stays_on_one_integration_run(): void
    {
        $sameCustomerOtherBrand = $this->createSameCustomerOtherBrandBinding();

        $this->materializeBindingFresh($this->bindingA, staleDatasetIds: []);
        $this->materializeBindingFresh($this->bindingB, staleDatasetIds: []);
        $this->materializeBindingFresh($sameCustomerOtherBrand, staleDatasetIds: ['meta_campaign_daily']);

        $result = app(MetaIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $this->assertNotNull($result->collectionRun);
        $this->assertSame(1, CollectionRun::query()->where('trigger_type', CollectionTriggerType::Incremental)->count());

        $planned = $result->collectionRun->resourceRuns()->pluck('core_asset_binding_id')->all();
        $this->assertContains($sameCustomerOtherBrand->id, $planned);
        $this->assertNotContains($this->bindingA->id, $planned);
        $this->assertNotContains($this->bindingB->id, $planned);

        $brandIds = $result->collectionRun->resourceRuns()
            ->with('digitalAsset')
            ->get()
            ->pluck('digitalAsset.brand_id')
            ->unique()
            ->values()
            ->all();
        $this->assertSame([(int) $sameCustomerOtherBrand->digitalAsset->brand_id], $brandIds);
    }

    #[Test]
    public function equivalent_incremental_start_reuses_the_active_run(): void
    {
        $this->materializeBindingFresh($this->bindingA, staleDatasetIds: []);
        $this->materializeBindingFresh($this->bindingB, staleDatasetIds: ['meta_campaign_daily']);

        $first = app(MetaIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);
        $this->assertSame('started', $first->outcome);

        $again = app(MetaIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);
        $this->assertSame('active_equivalent', $again->outcome);
        $this->assertTrue($again->reusedExisting);
        $this->assertSame($first->collectionRun?->id, $again->collectionRun?->id);
        $this->assertSame(1, CollectionRun::query()->where('trigger_type', CollectionTriggerType::Incremental)->count());
    }

    #[Test]
    public function collect_data_uses_incremental_when_initial_backfill_is_satisfied(): void
    {
        $this->satisfyInitialCoverage($this->bindingA);
        $this->satisfyInitialCoverage($this->bindingB, staleDatasetIds: ['meta_campaign_daily']);

        $preflight = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertSame('already_satisfied', $preflight->outcome);

        $this->actingAs($this->admin);
        Livewire::test(MetaIntegrationPage::class)
            ->call('collectData')
            ->assertHasNoErrors();

        $this->assertSame(0, CollectionRun::query()->where('trigger_type', CollectionTriggerType::InitialBackfill)->count());
        $this->assertSame(1, CollectionRun::query()->where('trigger_type', CollectionTriggerType::Incremental)->count());
        $run = CollectionRun::query()->where('trigger_type', CollectionTriggerType::Incremental)->first();
        $this->assertSame('Incremental Meta Ads Refresh', $run?->metadata['collection_intent_label'] ?? null);
        $this->assertContains($this->bindingB->id, $run?->resourceRuns()->pluck('core_asset_binding_id')->all() ?? []);
        Http::assertNothingSent();
    }

    private function createSameCustomerOtherBrandBinding(): CoreAssetBinding
    {
        $otherBrand = Brand::factory()->create([
            'customer_id' => $this->assetA->brand->customer_id,
            'name' => 'Sibling Brand',
        ]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_33330003',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone_name' => 'Europe/Berlin'],
        ]);

        return CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    private function createOtherCustomerBindingOnSameIntegration(): CoreAssetBinding
    {
        $otherCustomer = Customer::factory()->create();
        $otherBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_44440004',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone_name' => 'Europe/Berlin'],
        ]);

        return CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    private function createGoogleAdsBinding(): CoreAssetBinding
    {
        $google = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->assetA->brand_id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $google->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '1112223333',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        return CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  list<string>  $staleDatasetIds
     */
    private function materializeBindingFresh(CoreAssetBinding $binding, array $staleDatasetIds = [], bool $widenInitialBounds = false): void
    {
        $binding->loadMissing(['digitalAsset', 'externalResource']);
        $contracts = app(DataContractRegistryLoader::class);
        $contracts->load();
        $loader = app(DataFreshnessPolicyLoader::class);
        $collectableEnd = app(CollectableEndResolver::class);

        $familyIds = collect($contracts->requestFamilies())
            ->filter(static fn (array $family): bool => ($family['provider_or_source'] ?? '') === 'META_ADS'
                && ! in_array((string) ($family['status'] ?? ''), ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true))
            ->pluck('id')
            ->all();

        $timezone = $this->resourceTimezone($binding);
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
            if ($policy === null) {
                continue;
            }

            $end = $collectableEnd->resolve($policy, $timezone);
            $stale = in_array($datasetId, $staleDatasetIds, true);
            $mode = (string) ($policy['collection_mode'] ?? '');

            if (in_array($mode, ['CURRENT_SNAPSHOT', 'CONTROLLED_ON_DEMAND', 'STATIC_OR_SLOW_METADATA'], true)) {
                DatasetMaterialization::query()->create([
                    'dataset_id' => $datasetId,
                    'digital_asset_id' => $binding->digital_asset_id,
                    'external_resource_id' => $binding->external_resource_id,
                    'provider_or_source' => 'META_ADS',
                    'contract_version' => 1,
                    'status' => MaterializationStatus::Available,
                    'last_collected_at' => $stale
                        ? CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subDays(30)
                        : CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHours(2),
                    'row_count_approx' => 1,
                    'row_count_semantics' => 'approximate_from_batches',
                    'partial' => false,
                ]);

                continue;
            }

            if ($end === null) {
                continue;
            }

            $dates = $stale
                ? $this->contiguousDates('2026-08-01', 5)
                : $this->datesThrough($end, '2026-02-01');
            $extra = $stale ? [] : ['last_reprocess_through' => $end];
            $setDates = $dates;
            sort($setDates);
            $start = $setDates[0];
            $last = $setDates[array_key_last($setDates)];

            DatasetMaterialization::query()->create([
                'dataset_id' => $datasetId,
                'digital_asset_id' => $binding->digital_asset_id,
                'external_resource_id' => $binding->external_resource_id,
                'provider_or_source' => 'META_ADS',
                'contract_version' => 1,
                'status' => MaterializationStatus::Available,
                'last_collected_at' => CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHour(),
                'coverage_start_date' => $widenInitialBounds ? '2025-01-01' : $start,
                'coverage_end_date' => $widenInitialBounds ? '2026-12-31' : $last,
                'row_count_approx' => 0,
                'row_count_semantics' => 'approximate_from_batches',
                'partial' => false,
                'freshness_metadata' => array_merge([
                    'successful_coverage_dates' => $dates,
                    'verified_contiguous_watermark' => $last,
                    'latest_observed_reporting_date' => $last,
                    'last_successful_reporting_date' => $last,
                ], $extra),
            ]);
        }
    }

    /**
     * @param  list<string>  $staleDatasetIds
     */
    private function satisfyInitialCoverage(CoreAssetBinding $binding, array $staleDatasetIds = []): void
    {
        $this->materializeBindingFresh($binding, $staleDatasetIds, widenInitialBounds: true);
        $this->coverPlannerFamilyDatasets($binding);
    }

    private function coverPlannerFamilyDatasets(CoreAssetBinding $binding): void
    {
        $contracts = app(DataContractRegistryLoader::class);
        $contracts->load();

        foreach ($contracts->requestFamilies() as $family) {
            if (($family['provider_or_source'] ?? '') !== 'META_ADS') {
                continue;
            }
            if (in_array((string) ($family['status'] ?? ''), ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true)) {
                continue;
            }

            $familyId = (string) ($family['id'] ?? '');
            if ($familyId === '') {
                continue;
            }

            $datasetId = null;
            foreach ($contracts->requirements() as $requirement) {
                if (($requirement['request_family'] ?? null) === $familyId
                    && is_string($requirement['dataset'] ?? null)
                    && $requirement['dataset'] !== '') {
                    $datasetId = (string) $requirement['dataset'];
                    break;
                }
            }
            $datasetId ??= $familyId;

            $exists = DatasetMaterialization::query()
                ->where('dataset_id', $datasetId)
                ->where('digital_asset_id', $binding->digital_asset_id)
                ->where('external_resource_id', $binding->external_resource_id)
                ->exists();
            if ($exists) {
                continue;
            }

            DatasetMaterialization::query()->create([
                'dataset_id' => $datasetId,
                'digital_asset_id' => $binding->digital_asset_id,
                'external_resource_id' => $binding->external_resource_id,
                'provider_or_source' => 'META_ADS',
                'contract_version' => 1,
                'status' => MaterializationStatus::Available,
                'last_collected_at' => CarbonImmutable::parse(self::FROZEN_AT, 'UTC')->subHours(2),
                'coverage_start_date' => '2025-01-01',
                'coverage_end_date' => '2026-12-31',
                'row_count_approx' => 1,
                'row_count_semantics' => 'approximate_from_batches',
                'partial' => false,
            ]);
        }
    }

    private function resourceTimezone(CoreAssetBinding $binding): ?string
    {
        $meta = is_array($binding->externalResource?->metadata) ? $binding->externalResource->metadata : [];
        foreach (['timezone', 'timezone_name', 'timeZone', 'time_zone'] as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                return (string) $meta[$key];
            }
        }

        return null;
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

    /**
     * @return list<string>
     */
    private function datesThrough(string $endDate, string $startDate = '2026-08-01'): array
    {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);
        $count = $start->diffInDays($end) + 1;

        return $this->contiguousDates($startDate, $count);
    }
}
