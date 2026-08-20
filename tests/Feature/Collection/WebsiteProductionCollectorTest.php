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
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\User;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\Providers\Website\WebsiteDatasetExecutor;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\PageSpeedConnectionProbeService;
use App\Support\Roles;
use App\Support\SslCertificateProbe;
use Database\Seeders\RoleAndPermissionSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebsiteProductionCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $asset;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        Storage::fake('raw_ingestion');
        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'module_id' => 'website',
            'status' => DigitalAssetStatus::Active,
            'domain' => '1.1.1.1',
            'primary_url' => 'http://1.1.1.1/',
        ]);
    }

    #[Test]
    public function website_orchestrator_does_not_pull_google_or_meta_sibling_bindings(): void
    {
        Queue::fake();

        $google = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        $gscBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $google->id,
                'provider' => 'google',
                'resource_type' => 'search_console',
                'external_id' => 'sc-domain:example.com',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);

        $adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $adsBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $adsAsset->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $google->id,
                'provider' => 'google',
                'resource_type' => 'google_ads',
                'external_id' => '1234567890',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);

        $run = app(WebsiteCollectionOrchestrator::class)->start($this->asset, $this->admin);
        $providers = $run->resourceRuns->pluck('provider_or_source')->unique()->values()->all();
        $bindingIds = $run->resourceRuns->pluck('core_asset_binding_id')->filter()->values()->all();
        $families = $run->datasetRuns->pluck('request_family_id')->all();

        $this->assertNotContains('SEARCH_CONSOLE', $providers);
        $this->assertNotContains('GA4', $providers);
        $this->assertNotContains('GOOGLE_ADS', $providers);
        $this->assertNotContains('META_ADS', $providers);
        $this->assertContains('WEBSITE_DIRECT', $providers);
        $this->assertContains('DOMAIN_DNS_TLS', $providers);
        $this->assertSame([], $bindingIds);
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, $families);
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, $families);
        $this->assertNotContains(WebsiteRequestFamilyCatalog::FAMILY_WP_REST, $families);
        $this->assertSame(
            CollectionRunStatus::NotEligible,
            $run->datasetRuns->firstWhere('request_family_id', WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED)?->status,
        );

        $this->assertNotSame($gscBinding->id, $adsBinding->id);
    }

    #[Test]
    public function null_provider_sources_on_a_website_asset_do_not_auto_add_website_families(): void
    {
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => CoreIntegration::factory()->google()->create([
                    'status' => CoreIntegration::STATUS_ACTIVE,
                ])->id,
                'provider' => 'google',
                'resource_type' => 'search_console',
                'external_id' => 'sc-domain:example.com',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);

        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
        ));

        $providers = array_column($plan['resources'], 'provider_or_source');
        $families = array_column($plan['datasets'], 'request_family_id');
        $this->assertContains('SEARCH_CONSOLE', $providers);
        $this->assertNotContains('WEBSITE_DIRECT', $providers);
        $this->assertNotContains('DATAFORSEO', $providers);
        $this->assertNotContains(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, $families);
        $this->assertNotContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, $families);
    }

    #[Test]
    public function incremental_trigger_marks_website_families_not_eligible(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            triggerType: CollectionTriggerType::Incremental,
            providerSources: ['WEBSITE_DIRECT', 'DOMAIN_DNS_TLS'],
            requestFamilyIds: [
                WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS,
                WebsiteRequestFamilyCatalog::FAMILY_DNS_TLS,
            ],
        ));

        foreach ($plan['datasets'] as $dataset) {
            $this->assertSame(CollectionRunStatus::NotEligible->value, $dataset['planned_status']);
        }
    }

    #[Test]
    public function http_html_diagnosis_checkpoints_steps_and_does_not_multiply_on_resume_or_overlap(): void
    {
        $this->fakePublicSite();

        [$context, $datasetRun] = $this->makeContext(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS);
        $executor = app(WebsiteDatasetExecutor::class);

        $first = $executor->execute($context);
        $this->assertSame(DatasetExecutionOutcome::Continue, $first->outcome, (string) $first->errorMessage);
        $this->assertSame(1, $first->checkpoint['step_index'] ?? null);
        $observedAt = (string) $first->checkpoint['observed_at'];
        app(CheckpointManager::class)->advance($datasetRun, $first->checkpoint);

        $homepageHttp = DB::table('website_http_snapshot')->count();
        $homepageUrls = DB::table('website_url')->count();
        $this->assertGreaterThan(0, $homepageHttp);
        $this->assertGreaterThan(0, $homepageUrls);

        $second = $executor->execute($this->contextFrom($context, $datasetRun, $first->checkpoint));
        $this->assertSame(DatasetExecutionOutcome::Continue, $second->outcome, (string) $second->errorMessage);
        $this->assertSame(2, $second->checkpoint['step_index'] ?? null);
        $this->assertSame($observedAt, $second->checkpoint['observed_at'] ?? null);
        $this->assertSame($homepageUrls, DB::table('website_url')->count(), 'robots tick must not duplicate homepage URL inventory');

        $third = $this->runUntilComplete($executor, $this->contextFrom($context, $datasetRun, $second->checkpoint), $datasetRun, $second);
        $this->assertSame(DatasetExecutionOutcome::Completed, $third->outcome, (string) $third->errorMessage);

        $urlsAfter = DB::table('website_url')->count();
        $httpAfter = DB::table('website_http_snapshot')->count();
        $this->assertGreaterThan($homepageHttp, $httpAfter, 'robots and sitemap ticks must add HTTP snapshots');
        $this->assertSame(0, Evidence::query()->count());

        $overlap = $this->runUntilComplete(
            $executor,
            $this->contextFrom($context, $datasetRun, [
                'step_index' => 0,
                'observed_at' => $observedAt,
                'rows_written_total' => 0,
            ]),
            $datasetRun,
        );
        $this->assertSame(DatasetExecutionOutcome::Completed, $overlap->outcome, (string) $overlap->errorMessage);
        $this->assertSame($urlsAfter, DB::table('website_url')->count());
        $this->assertSame($httpAfter, DB::table('website_http_snapshot')->count());
    }

    #[Test]
    public function dns_tls_writes_one_infra_snapshot_via_injected_probe(): void
    {
        $probe = new class extends SslCertificateProbe
        {
            public function probe(string $host, DateTimeInterface $observedAt, int $port = 443): array
            {
                return [
                    'subject_common_name' => $host,
                    'issuer_common_name' => 'Test CA',
                    'valid_from' => '2026-01-01 00:00:00',
                    'valid_to' => '2027-01-01 00:00:00',
                    'observed_at' => $observedAt->format('Y-m-d H:i:s'),
                    'fetch_method' => 'test',
                    'host' => $host,
                    'present' => true,
                ];
            }
        };
        $this->app->instance(SslCertificateProbe::class, $probe);
        $this->app->forgetInstance(WebsiteDatasetExecutor::class);

        $result = $this->runFamily(WebsiteRequestFamilyCatalog::FAMILY_DNS_TLS);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $this->assertSame(1, DB::table('website_infra_snapshot')->count());
        $row = DB::table('website_infra_snapshot')->first();
        $this->assertSame((string) $this->asset->id, $row->asset_id);
        $this->assertSame(0, Evidence::query()->count());
    }

    #[Test]
    public function pagespeed_without_connection_is_not_eligible_and_executor_does_not_call_psi(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            providerSources: ['PAGESPEED_TECHNICAL'],
            requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED],
        ));
        $this->assertSame(CollectionRunStatus::NotEligible->value, $plan['datasets'][0]['planned_status']);

        Http::fake();
        $result = $this->runFamily(WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('PAGESPEED_CONNECTION_REQUIRED', $result->errorCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function pagespeed_with_connection_writes_performance_measurement(): void
    {
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'type' => PageSpeedConnectionProbeService::CONNECTION_TYPE,
            'enabled' => true,
            'config' => [
                'strategy' => 'mobile',
                'url' => 'http://1.1.1.1/',
            ],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['api_key' => 'psi-test-key'],
        ]);

        Http::fake([
            'https://www.googleapis.com/pagespeedonline/v5/runPagespeed*' => Http::response([
                'lighthouseResult' => [
                    'finalUrl' => 'http://1.1.1.1/',
                    'fetchTime' => '2026-08-20T00:00:00.000Z',
                    'audits' => [
                        'largest-contentful-paint' => ['numericValue' => 2400],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->runFamily(WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $this->assertSame(1, DB::table('website_performance_measurement')->count());
        $this->assertSame(0, Evidence::query()->count());
    }

    #[Test]
    public function sibling_resource_run_binding_is_rejected(): void
    {
        $this->fakePublicSite();
        [$context, $datasetRun] = $this->makeContext(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => CoreIntegration::factory()->google()->create([
                    'status' => CoreIntegration::STATUS_ACTIVE,
                ])->id,
                'provider' => 'google',
                'resource_type' => 'search_console',
                'external_id' => 'sc-domain:1.1.1.1',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);
        $context->resourceRun->forceFill(['core_asset_binding_id' => $binding->id])->save();

        $result = app(WebsiteDatasetExecutor::class)->execute($this->contextFrom(
            $context,
            $datasetRun,
            [],
        ));
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('BINDING_NOT_USED', $result->errorCode);
    }

    private function fakePublicSite(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'robots.txt')) {
                return Http::response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/plain']);
            }
            if (str_contains($url, 'sitemap.xml')) {
                return Http::response(
                    '<?xml version="1.0"?><urlset><loc>http://1.1.1.1/page-a</loc></urlset>',
                    200,
                    ['Content-Type' => 'application/xml'],
                );
            }

            return Http::response(
                '<html><head><title>Clinic</title><meta name="description" content="Demo"></head><body><h1>Clinic</h1><a href="/about">About</a></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            );
        });
    }

    private function runFamily(string $family): DatasetExecutionResult
    {
        [$context, $datasetRun] = $this->makeContext($family);

        return $this->runUntilComplete(app(WebsiteDatasetExecutor::class), $context, $datasetRun);
    }

    private function runUntilComplete(
        WebsiteDatasetExecutor $executor,
        DatasetExecutionContext $context,
        CollectionDatasetRun $datasetRun,
        ?DatasetExecutionResult $seed = null,
    ): DatasetExecutionResult {
        $result = $seed ?? $executor->execute($context);
        $guard = 0;
        while ($result->outcome === DatasetExecutionOutcome::Continue && $guard < 40) {
            $guard++;
            if ($result->checkpoint !== null) {
                app(CheckpointManager::class)->advance($datasetRun, $result->checkpoint);
            }
            $result = $executor->execute($this->contextFrom($context, $datasetRun, $result->checkpoint ?? []));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    private function contextFrom(
        DatasetExecutionContext $context,
        CollectionDatasetRun $datasetRun,
        array $checkpoint,
    ): DatasetExecutionContext {
        return new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: $checkpoint,
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 1,
        );
    }

    /**
     * @return array{0: DatasetExecutionContext, 1: CollectionDatasetRun}
     */
    private function makeContext(string $family): array
    {
        $definition = WebsiteRequestFamilyCatalog::definition($family);
        $provider = match ($family) {
            WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED => 'PAGESPEED_TECHNICAL',
            WebsiteRequestFamilyCatalog::FAMILY_DNS_TLS => 'DOMAIN_DNS_TLS',
            default => 'WEBSITE_DIRECT',
        };

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->brand->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => [
                'context' => ['collection_intent' => 'website_production_collection'],
            ],
        ]);

        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => $provider,
            'resource_kind' => 'website_asset_capability',
            'external_resource_id' => null,
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => null,
            'status' => CollectionRunStatus::Running,
        ]);

        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => $provider,
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
}
