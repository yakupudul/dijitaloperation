<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
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
use App\Services\Collection\Google\GoogleIncrementalCollectionOrchestrator;
use App\Services\Collection\Google\GoogleInitialBackfillOrchestrator;
use App\Services\Collection\Support\CollectionClock;
use App\Services\DataPool\Freshness\CollectableEndResolver;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\DataPool\Freshness\DatasetWatermarkCalculator;
use App\Services\DataPool\Freshness\IncrementalCoveragePlanner;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleIncrementalCollectionOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN_AT = '2026-08-13 15:00:00';

    private User $admin;

    private CoreIntegration $integration;

    private DigitalAsset $gscAsset;

    private DigitalAsset $ga4Asset;

    private DigitalAsset $adsAsset;

    private CoreAssetBinding $gscBinding;

    private CoreAssetBinding $ga4Binding;

    private CoreAssetBinding $adsBinding;

    private CoreExternalResource $gscResource;

    private CoreExternalResource $ga4Resource;

    private CoreExternalResource $adsResource;

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
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        $this->gscAsset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $this->ga4Asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $this->adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_status' => 'connected',
                'granted_scopes' => [
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::ADWORDS,
                ],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'dev-token',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'scope' => implode(' ', [
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::ADWORDS,
                ]),
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->gscResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:example.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $this->ga4Resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'ga4',
            'external_id' => 'properties/123',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Berlin'],
        ]);
        $this->adsResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '1112223333',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'Europe/Berlin'],
        ]);

        $this->gscBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->gscAsset->id,
            'external_resource_id' => $this->gscResource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->ga4Binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->ga4Asset->id,
            'external_resource_id' => $this->ga4Resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->adsBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->adsAsset->id,
            'external_resource_id' => $this->adsResource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function sibling_ga4_due_work_starts_instead_of_data_current_and_excludes_other_tenants(): void
    {
        $otherBrandBinding = $this->createOtherBrandAdsBinding();
        $otherCustomerBinding = $this->createOtherCustomerAdsBinding();

        $this->materializeBindingFresh($this->gscBinding, 'SEARCH_CONSOLE');
        $this->materializeBindingFresh($this->adsBinding, 'GOOGLE_ADS');
        $this->materializeBindingFresh($this->ga4Binding, 'GA4', staleDatasetIds: ['ga4_property_daily']);
        $this->materializeBindingFresh($otherBrandBinding, 'GOOGLE_ADS');
        $this->materializeBindingFresh($otherCustomerBinding, 'GOOGLE_ADS');

        $preflight = app(GoogleInitialBackfillOrchestrator::class)
            ->preflightByBrand($this->integration->fresh())[0];
        $this->assertContains($this->ga4Binding->id, $preflight->eligibleBindingIds);
        $this->assertContains($this->gscBinding->id, $preflight->eligibleBindingIds);
        $this->assertContains($this->adsBinding->id, $preflight->eligibleBindingIds);
        $this->assertNotContains($otherBrandBinding->id, $preflight->eligibleBindingIds);
        $this->assertNotContains($otherCustomerBinding->id, $preflight->eligibleBindingIds);

        $result = app(GoogleIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $this->assertNotNull($result->collectionRun);
        $this->assertSame(CollectionTriggerType::Incremental, $result->collectionRun->trigger_type);
        $this->assertCount(1, $result->collectionRuns);
        $this->assertCount(3, $result->brandResults);
        $outcomes = collect($result->brandResults)->pluck('outcome')->all();
        $this->assertContains('started', $outcomes);
        $this->assertSame(2, count(array_filter($outcomes, static fn (string $o): bool => $o === 'data_current')));
        $this->assertStringContainsString('DATA CURRENT', $result->message);

        $plannedBindingIds = $result->collectionRun->resourceRuns()->pluck('core_asset_binding_id')->all();
        $this->assertContains($this->ga4Binding->id, $plannedBindingIds);
        $this->assertNotContains($this->gscBinding->id, $plannedBindingIds);
        $this->assertNotContains($this->adsBinding->id, $plannedBindingIds);
        $this->assertNotContains($otherBrandBinding->id, $plannedBindingIds);
        $this->assertNotContains($otherCustomerBinding->id, $plannedBindingIds);

        $decisionBindingIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['core_asset_binding_id'],
            $result->decisions,
        )));
        $this->assertContains($this->ga4Binding->id, $decisionBindingIds);
        $this->assertNotContains($otherBrandBinding->id, $decisionBindingIds);
        $this->assertNotContains($otherCustomerBinding->id, $decisionBindingIds);

        $queuedFamilies = $result->collectionRun->datasetRuns()
            ->where('status', CollectionRunStatus::Queued)
            ->pluck('request_family_id')
            ->all();
        $this->assertContains('GA4_RF_PROPERTY_DAILY', $queuedFamilies);
        $this->assertTrue(collect($queuedFamilies)->every(
            static fn (string $familyId): bool => str_starts_with($familyId, 'GA4_'),
        ));
    }

    #[Test]
    public function multi_brand_incremental_starts_one_run_per_due_brand_and_reports_data_current_siblings(): void
    {
        $otherBrandBinding = $this->createOtherBrandAdsBinding();

        $this->materializeBindingFresh($this->gscBinding, 'SEARCH_CONSOLE');
        $this->materializeBindingFresh($this->adsBinding, 'GOOGLE_ADS');
        $this->materializeBindingFresh($this->ga4Binding, 'GA4', staleDatasetIds: ['ga4_property_daily']);
        $this->materializeBindingFresh($otherBrandBinding, 'GOOGLE_ADS');

        $result = app(GoogleIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $this->assertCount(1, $result->collectionRuns);
        $this->assertCount(2, $result->brandResults);
        $this->assertStringContainsString('1 Brand', $result->message);
        $this->assertStringContainsString('DATA CURRENT', $result->message);
        $this->assertStringNotContainsString('started for 2 Brands', $result->message);

        $outcomes = collect($result->brandResults)->pluck('outcome', 'brand_id');
        $this->assertSame('started', $outcomes[(int) $this->gscAsset->brand_id]);
        $this->assertSame('data_current', $outcomes[(int) $otherBrandBinding->digitalAsset->brand_id]);

        $homeRun = $result->collectionRuns[0];
        $planned = $homeRun->resourceRuns()->pluck('core_asset_binding_id')->all();
        $this->assertContains($this->ga4Binding->id, $planned);
        $this->assertNotContains($otherBrandBinding->id, $planned);
    }

    #[Test]
    public function multi_brand_incremental_starts_one_run_per_brand_when_both_are_due(): void
    {
        $otherBrandBinding = $this->createOtherBrandAdsBinding();

        $this->materializeBindingFresh($this->gscBinding, 'SEARCH_CONSOLE');
        $this->materializeBindingFresh($this->adsBinding, 'GOOGLE_ADS');
        $this->materializeBindingFresh($this->ga4Binding, 'GA4', staleDatasetIds: ['ga4_property_daily']);
        $this->materializeBindingFresh($otherBrandBinding, 'GOOGLE_ADS', staleDatasetIds: ['google_ads_campaign_daily']);

        $result = app(GoogleIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $this->assertCount(2, $result->collectionRuns);
        $this->assertCount(2, $result->brandResults);
        $this->assertSame(['started', 'started'], array_column($result->brandResults, 'outcome'));
        $this->assertStringContainsString('started for 2 Brands', $result->message);
        $this->assertCount(2, $result->toArray()['collection_run_uuids']);

        $bindingsByRun = [];
        foreach ($result->collectionRuns as $run) {
            $planned = $run->resourceRuns()->pluck('core_asset_binding_id')->all();
            $bindingsByRun[$run->id] = $planned;
            $this->assertTrue(in_array($this->ga4Binding->id, $planned, true) xor in_array($otherBrandBinding->id, $planned, true));
            $brandIds = $run->resourceRuns()
                ->with('digitalAsset')
                ->get()
                ->pluck('digitalAsset.brand_id')
                ->unique()
                ->values()
                ->all();
            $this->assertCount(1, $brandIds);
        }

        $allPlanned = array_merge(...array_values($bindingsByRun));
        $this->assertContains($this->ga4Binding->id, $allPlanned);
        $this->assertContains($otherBrandBinding->id, $allPlanned);

        $again = app(GoogleIncrementalCollectionOrchestrator::class)
            ->start($this->integration->fresh(), $this->admin);
        $this->assertSame('active_equivalent', $again->outcome);
        $this->assertCount(2, $again->collectionRuns);
        $this->assertSame(2, CollectionRun::query()->where('trigger_type', CollectionTriggerType::Incremental)->count());
    }

    #[Test]
    public function collect_data_does_not_select_one_brand_run_when_multiple_incremental_runs_start(): void
    {
        $otherBrandBinding = $this->createOtherBrandAdsBinding();
        $this->satisfyInitialCoverage($this->gscBinding, 'SEARCH_CONSOLE');
        $this->satisfyInitialCoverage($this->adsBinding, 'GOOGLE_ADS');
        $this->satisfyInitialCoverage($this->ga4Binding, 'GA4', staleDatasetIds: ['ga4_property_daily']);
        $this->satisfyInitialCoverage($otherBrandBinding, 'GOOGLE_ADS', staleDatasetIds: ['google_ads_campaign_daily']);

        $preflight = app(GoogleInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertSame('already_satisfied', $preflight->outcome);

        $this->actingAs($this->admin);
        Livewire::test(GoogleIntegrationPage::class)
            ->call('collectData')
            ->assertHasNoErrors()
            ->assertNotDispatched('collection-run-selected')
            ->assertSee('started for 2 Brands');

        $this->assertSame(2, CollectionRun::query()->where('trigger_type', CollectionTriggerType::Incremental)->count());
    }

    private function createOtherBrandAdsBinding(): CoreAssetBinding
    {
        $otherBrand = Brand::factory()->create(['customer_id' => $this->gscAsset->brand->customer_id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '4445556666',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'Europe/Berlin'],
        ]);

        return CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    private function createOtherCustomerAdsBinding(): CoreAssetBinding
    {
        $otherCustomer = Customer::factory()->create();
        $otherBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '7778889999',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'Europe/Berlin'],
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
    private function materializeBindingFresh(
        CoreAssetBinding $binding,
        string $provider,
        array $staleDatasetIds = [],
        bool $widenInitialBounds = false,
    ): void {
        $binding->loadMissing(['digitalAsset', 'externalResource']);
        $contracts = app(DataContractRegistryLoader::class);
        $contracts->load();
        $loader = app(DataFreshnessPolicyLoader::class);
        $collectableEnd = app(CollectableEndResolver::class);

        $familyIds = collect($contracts->requestFamilies())
            ->filter(static fn (array $family): bool => ($family['provider_or_source'] ?? '') === $provider
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
                    'provider_or_source' => $provider,
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
                'provider_or_source' => $provider,
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
    private function satisfyInitialCoverage(CoreAssetBinding $binding, string $provider, array $staleDatasetIds = []): void
    {
        $this->materializeBindingFresh($binding, $provider, $staleDatasetIds, widenInitialBounds: true);
        $this->coverPlannerFamilyDatasets($binding, $provider);
    }

    private function coverPlannerFamilyDatasets(CoreAssetBinding $binding, string $provider): void
    {
        $contracts = app(DataContractRegistryLoader::class);
        $contracts->load();

        foreach ($contracts->requestFamilies() as $family) {
            if (($family['provider_or_source'] ?? '') !== $provider) {
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
                'provider_or_source' => $provider,
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
