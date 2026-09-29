<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\DataForSeo\DataForSeoEnrichmentOrchestrator;
use App\Services\Collection\Providers\DataForSeo\DataForSeoDatasetExecutor;
use App\Services\Collection\Providers\DataForSeo\DataForSeoRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataForSeoProductionCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $asset;

    private CoreIntegration $integration;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        Storage::fake('raw_ingestion');
        config([
            'moxdop.dataforseo.login' => null,
            'moxdop.dataforseo.password' => null,
            'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com',
            'moxdop.seo_intelligence.ranked_keywords.ttl_days' => 5,
            'moxdop.seo_intelligence.ranked_keywords.limit' => 100,
            'cache.default' => 'array',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
        ]);
        Cache::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'module_id' => 'website',
            'status' => DigitalAssetStatus::Active,
            'domain' => 'https://www.moximu.com/',
            'primary_url' => 'https://www.moximu.com/',
            'seo_market_location_code' => 2792,
            'seo_market_location_name' => 'Turkey',
            'seo_market_language_code' => 'tr',
            'seo_market_language_name' => 'Turkish',
        ]);

        $this->integration = CoreIntegration::factory()->dataforseo()->create();
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);
    }

    #[Test]
    public function competitor_family_requires_public_discovery_flag(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            providerSources: ['DATAFORSEO'],
            requestFamilyIds: [DataForSeoRequestFamilyCatalog::FAMILY_COMPETITORS_DOMAIN],
            context: ['paid_enrichment_consented' => true],
        ));
        $this->assertSame(CollectionRunStatus::NotEligible->value, $plan['datasets'][0]['planned_status']);

        Http::fake();
        $result = $this->runFamily(
            DataForSeoRequestFamilyCatalog::FAMILY_COMPETITORS_DOMAIN,
            consented: true,
            discovery: false,
        );
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('DISCOVERY_REQUEST_REQUIRED', $result->errorCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function enrichment_orchestrator_stays_on_the_website_asset_and_skips_sibling_ads(): void
    {
        Queue::fake();

        DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'provider' => 'google',
                'resource_type' => 'search_console',
                'external_id' => 'sc-domain:moximu.com',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);

        $run = app(DataForSeoEnrichmentOrchestrator::class)->start(
            $this->asset,
            $this->admin,
            paidEnrichmentConsented: true,
            publicDiscovery: true,
        );

        $providers = $run->resourceRuns->pluck('provider_or_source')->unique()->all();
        $this->assertSame(['DATAFORSEO'], array_values($providers));
        $this->assertTrue($run->resourceRuns->every(fn ($resource): bool => $resource->core_asset_binding_id === null));
        $this->assertTrue($run->resourceRuns->every(fn ($resource): bool => (int) $resource->digital_asset_id === (int) $this->asset->id));
        $this->assertNotContains('SEARCH_CONSOLE', $providers);
        $this->assertNotContains('GOOGLE_ADS', $providers);
        $this->assertNotContains('META_ADS', $providers);
    }

    #[Test]
    public function incremental_trigger_marks_dataforseo_not_eligible(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            triggerType: CollectionTriggerType::Incremental,
            providerSources: ['DATAFORSEO'],
            requestFamilyIds: [DataForSeoRequestFamilyCatalog::FAMILY_FREE_USER],
        ));
        $this->assertSame(CollectionRunStatus::NotEligible->value, $plan['datasets'][0]['planned_status']);
    }

    private function runFamily(string $family, bool $consented = false, bool $discovery = false): DatasetExecutionResult
    {
        [$context, $datasetRun] = $this->makeContext($family, $consented, $discovery);

        return app(DataForSeoDatasetExecutor::class)->execute($context);
    }

    private function replay(DatasetExecutionContext $original, CollectionDatasetRun $datasetRun, int $attemptNumber = 2): DatasetExecutionResult
    {
        $datasetRun = $datasetRun->fresh() ?? $datasetRun;

        return app(DataForSeoDatasetExecutor::class)->execute(new DatasetExecutionContext(
            collectionRun: $original->collectionRun->fresh() ?? $original->collectionRun,
            resourceRun: $original->resourceRun->fresh() ?? $original->resourceRun,
            datasetRun: $datasetRun,
            checkpoint: is_array($datasetRun->checkpoint) ? $datasetRun->checkpoint : [],
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: $attemptNumber,
        ));
    }

    /**
     * @return array{0: DatasetExecutionContext, 1: CollectionDatasetRun}
     */
    private function makeContext(
        string $family,
        bool $consented = false,
        bool $discovery = false,
        ?DigitalAsset $asset = null,
        bool $forceRefresh = false,
    ): array {
        $asset ??= $this->asset;
        $definition = DataForSeoRequestFamilyCatalog::definition($family);

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->brand->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => [
                'force_refresh' => $forceRefresh,
                'context' => [
                    'paid_enrichment_consented' => $consented,
                    'public_discovery' => $discovery,
                ],
            ],
        ]);

        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'DATAFORSEO',
            'resource_kind' => 'website_asset_capability',
            'external_resource_id' => null,
            'digital_asset_id' => $asset->id,
            'core_asset_binding_id' => null,
            'status' => CollectionRunStatus::Running,
        ]);

        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'DATAFORSEO',
            'dataset_contract_id' => $definition['dataset_ids'][0],
            'request_family_id' => $family,
            'contract_registry_version' => 1,
            'status' => CollectionRunStatus::Running,
        ]);

        return [
            new DatasetExecutionContext(
                collectionRun: $run,
                resourceRun: $resourceRun,
                datasetRun: $datasetRun,
                checkpoint: [],
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: 1,
            ),
            $datasetRun,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rankedKeywordsFixture(bool $includeVolume = true): array
    {
        return [
            'version' => '0.1.20260101',
            'status_code' => 20000,
            'status_message' => 'Ok.',
            'cost' => 0.0123,
            'tasks_count' => 1,
            'tasks_error' => 0,
            'tasks' => [[
                'id' => '00000000-0000-0000-0000-000000000001',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => 0.0123,
                'result_count' => 1,
                'result' => [[
                    'se_type' => 'google',
                    'target' => 'moximu.com',
                    'location_code' => 2792,
                    'language_code' => 'tr',
                    'total_count' => 1,
                    'items_count' => 1,
                    'metrics' => [
                        'organic' => [
                            'pos_1' => 1,
                            'count' => 1,
                            'etv' => 12.2,
                        ],
                    ],
                    'items' => [[
                        'keyword_data' => [
                            'keyword' => 'seo agency',
                            'keyword_info' => $includeVolume ? [
                                'search_volume' => 720,
                                'cpc' => 1.25,
                            ] : [],
                            'keyword_properties' => ['keyword_difficulty' => 40],
                        ],
                        'ranked_serp_element' => [
                            'serp_item' => [
                                'type' => 'organic',
                                'rank_group' => 8,
                                'rank_absolute' => 10,
                                'url' => 'https://moximu.com/services',
                                'etv' => $includeVolume ? 12.2 : null,
                            ],
                        ],
                    ]],
                ]],
            ]],
        ];
    }
}
