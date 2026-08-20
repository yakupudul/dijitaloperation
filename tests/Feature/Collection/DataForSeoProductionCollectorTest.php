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
use App\Models\Evidence;
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
use Illuminate\Support\Facades\DB;
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
    public function paid_family_without_consent_is_not_eligible_and_does_not_post(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            providerSources: ['DATAFORSEO'],
            requestFamilyIds: [DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS],
        ));
        $this->assertSame(CollectionRunStatus::NotEligible->value, $plan['datasets'][0]['planned_status']);

        Http::fake();
        $result = $this->runFamily(DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS, consented: false);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('PAID_CONSENT_REQUIRED', $result->errorCode);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('dataforseo_ranked_keyword_snapshot')->count());
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
    public function ranked_keywords_miss_writes_facts_hit_skips_post_and_does_not_multiply(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::response(
                $this->rankedKeywordsFixture(),
                200,
            ),
        ]);

        $first = $this->runFamily(DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS, consented: true);
        $this->assertSame(DatasetExecutionOutcome::Completed, $first->outcome, (string) $first->errorMessage);
        $this->assertSame('MISS', $first->checkpoint['cache_status'] ?? null);
        $this->assertSame(1, DB::table('dataforseo_ranked_keyword_snapshot')->count());
        $row = DB::table('dataforseo_ranked_keyword_snapshot')->first();
        $this->assertSame('moximu.com', $row->target);
        $this->assertSame('seo agency', $row->keyword);
        $this->assertEqualsWithDelta(12.2, (float) $row->etv, 0.0001);
        $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
        $this->assertFalse($metadata['search_volume_missing'] ?? true);
        $this->assertSame(0, Evidence::query()->count());
        Http::assertSentCount(1);

        $second = $this->runFamily(DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS, consented: true);
        $this->assertSame(DatasetExecutionOutcome::Completed, $second->outcome, (string) $second->errorMessage);
        $this->assertSame('HIT_FRESH', $second->checkpoint['cache_status'] ?? null);
        $this->assertFalse($second->checkpoint['provider_called'] ?? true);
        $this->assertSame(1, DB::table('dataforseo_ranked_keyword_snapshot')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function paid_retry_after_raw_without_facts_does_not_post_again(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::response(
                $this->rankedKeywordsFixture(),
                200,
            ),
        ]);

        $first = $this->runFamily(DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS, consented: true);
        $this->assertSame(DatasetExecutionOutcome::Completed, $first->outcome, (string) $first->errorMessage);
        Http::assertSentCount(1);

        [$context, $datasetRun] = $this->makeContext(
            DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS,
            consented: true,
        );
        $ambiguous = app(DataForSeoDatasetExecutor::class)->execute(new DatasetExecutionContext(
            collectionRun: $context->collectionRun,
            resourceRun: $context->resourceRun,
            datasetRun: $datasetRun,
            checkpoint: [
                'paid_called' => true,
                'normalized' => false,
                'request_fingerprint' => $first->checkpoint['request_fingerprint'] ?? 'fp',
                'retrieved_at' => $first->checkpoint['retrieved_at'] ?? now()->toDateTimeString(),
            ],
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 2,
        ));

        $this->assertSame(DatasetExecutionOutcome::Failed, $ambiguous->outcome);
        $this->assertSame('CHARGE_UNKNOWN', $ambiguous->errorCode);
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('dataforseo_ranked_keyword_snapshot')->count());
    }

    #[Test]
    public function paid_http_500_is_charge_unknown_and_is_not_retried(): void
    {
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;

                return Http::response(['status_code' => 50000, 'status_message' => 'Internal error'], 500);
            }

            return Http::response(['status_code' => 20000, 'tasks' => []], 200);
        });

        $first = $this->runFamily(DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS, consented: true);
        $this->assertSame(DatasetExecutionOutcome::Failed, $first->outcome);
        $this->assertSame('CHARGE_UNKNOWN', $first->errorCode);
        $this->assertNotSame(DatasetExecutionOutcome::Retry, $first->outcome);
        $this->assertSame(1, $posts);

        $second = $this->runFamily(DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS, consented: true);
        $this->assertSame(DatasetExecutionOutcome::Failed, $second->outcome);
        $this->assertSame(2, $posts, 'A new DatasetRun may POST once; engine Retry must not multiply inside one execute');
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

    #[Test]
    public function missing_search_volume_is_not_a_measured_zero_without_provenance(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::response(
                $this->rankedKeywordsFixture(includeVolume: false),
                200,
            ),
        ]);

        $result = $this->runFamily(DataForSeoRequestFamilyCatalog::FAMILY_RANKED_KEYWORDS, consented: true);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $row = DB::table('dataforseo_ranked_keyword_snapshot')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->search_volume);
        $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
        $this->assertTrue($metadata['search_volume_missing'] ?? false);
        $this->assertTrue($metadata['etv_missing'] ?? false);
        $this->assertEqualsWithDelta(0.0, (float) $row->etv, 0.0001);
    }

    private function runFamily(string $family, bool $consented = false, bool $discovery = false): DatasetExecutionResult
    {
        [$context, $datasetRun] = $this->makeContext($family, $consented, $discovery);

        return app(DataForSeoDatasetExecutor::class)->execute($context);
    }

    /**
     * @return array{0: DatasetExecutionContext, 1: CollectionDatasetRun}
     */
    private function makeContext(string $family, bool $consented = false, bool $discovery = false): array
    {
        $definition = DataForSeoRequestFamilyCatalog::definition($family);

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->brand->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => [
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
            'digital_asset_id' => $this->asset->id,
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
