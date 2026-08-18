<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleDatasetExecutor;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleProviderCapabilities;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GscProductionCollectorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $asset;

    private CoreIntegration $integration;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        Storage::fake('raw_ingestion');
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'dev',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-collection.queue' => 'collection',
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
            'moxdop-gsc-collector.page_size' => 25000,
            'moxdop-gsc-collector.max_pages_per_tick' => 50,
            'moxdop-gsc-collector.date_slice_days' => [
                'GSC_RF_PROPERTY_DAILY' => 28,
                'GSC_RF_QUERY_DAILY' => 1,
                'GSC_RF_PAGE_DAILY' => 1,
                'GSC_RF_QUERY_PAGE_DAILY' => 1,
                'GSC_RF_DEVICE_DAILY' => 28,
                'GSC_RF_COUNTRY_DAILY' => 7,
            ],
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'gsc',
            'status' => DigitalAssetStatus::Active,
        ]);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'gsc-access-token',
                'refresh_token' => 'gsc-refresh-token',
                'scope' => GoogleScopes::SEARCH_CONSOLE_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:example.com',
            'display_name' => 'example.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function planner_maps_search_console_to_registry_provider_and_skips_appearance(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));

        $this->assertSame('SEARCH_CONSOLE', $plan['resources'][0]['provider_or_source']);
        $families = array_column($plan['datasets'], 'request_family_id');
        $this->assertContains(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY, $families);
        $this->assertContains(SearchConsoleRequestFamilyCatalog::FAMILY_SITEMAPS, $families);
        $this->assertNotContains('GSC_RF_APPEARANCE_DAILY', $families);

        $inspection = collect($plan['datasets'])->firstWhere('request_family_id', 'GSC_RF_URL_INSPECTION');
        $this->assertSame(CollectionRunStatus::NotEligible->value, $inspection['planned_status']);
    }

    #[Test]
    public function discovered_unbound_resource_is_not_planned(): void
    {
        $this->binding->forceFill(['status' => CoreAssetBinding::STATUS_DISABLED])->save();

        $this->expectException(\InvalidArgumentException::class);
        app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));
    }

    #[Test]
    public function property_daily_normalizes_and_persists_without_reconstructing_from_queries(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'responseAggregationType' => 'byProperty',
                'rows' => [
                    ['keys' => ['2026-08-01'], 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 4.5],
                    ['keys' => ['2026-08-02'], 'clicks' => 0, 'impressions' => 5, 'ctr' => 0.0, 'position' => 12.0],
                ],
            ], 200),
        ]);

        $result = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY);
        $this->assertSame(
            DatasetExecutionOutcome::Completed,
            $result->outcome,
            ($result->errorCode ?? '').': '.($result->errorMessage ?? ''),
        );
        $this->assertSame(2, DB::table('gsc_property_daily')->count());

        $row = DB::table('gsc_property_daily')->where('reporting_date', '2026-08-01')->first();
        $this->assertSame('sc-domain:example.com', $row->site_url);
        $this->assertSame(10, (int) $row->clicks);
        $this->assertSame(100, (int) $row->impressions);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame(4.5, $meta['provider_average_position']);
        $this->assertSame('provider_reported_not_canonical_formula', $meta['provider_ctr_semantic']);
        $this->assertSame(SearchConsoleProviderCapabilities::PROVIDER_COMPLETENESS, $meta['provider_completeness']);
        $this->assertArrayNotHasKey('rank', $meta);

        $request = Http::recorded()[0][0];
        $body = $request->data();
        $this->assertSame(['date'], $body['dimensions']);
        $this->assertSame('web', $body['type']);
        $this->assertSame('final', $body['dataState']);
        $this->assertSame('byProperty', $body['aggregationType']);
        $this->assertArrayNotHasKey('access_token', $body);
    }

    #[Test]
    public function query_page_and_device_country_preserve_grain_and_exact_query_text(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push([
                    'rows' => [
                        [
                            'keys' => ['2026-08-01', 'Exact Query CASE', 'https://example.com/A'],
                            'clicks' => 1,
                            'impressions' => 2,
                            'ctr' => 0.5,
                            'position' => 3.0,
                        ],
                    ],
                ])
                ->push([
                    'rows' => [
                        ['keys' => ['2026-08-01', 'MOBILE'], 'clicks' => 1, 'impressions' => 2, 'ctr' => 0.5, 'position' => 1.0],
                    ],
                ])
                ->push([
                    'rows' => [
                        ['keys' => ['2026-08-01', 'usa'], 'clicks' => 1, 'impressions' => 2, 'ctr' => 0.5, 'position' => 1.0],
                    ],
                ])
                ->push([
                    'rows' => [
                        ['keys' => ['2026-08-01', 'https://example.com/page'], 'clicks' => 3, 'impressions' => 9, 'ctr' => 0.33, 'position' => 2.0],
                    ],
                ])
                ->push([
                    'rows' => [
                        ['keys' => ['2026-08-01', 'q'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1.0, 'position' => 1.0],
                    ],
                ]),
        ]);

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_QUERY_PAGE_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(1, DB::table('gsc_query_page_daily')->count());
        $qp = DB::table('gsc_query_page_daily')->first();
        $this->assertSame('Exact Query CASE', $qp->query);
        $this->assertSame('https://example.com/A', $qp->page);

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_DEVICE_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame('MOBILE', DB::table('gsc_device_daily')->value('device'));

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_COUNTRY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame('usa', DB::table('gsc_country_daily')->value('country'));

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PAGE_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame('https://example.com/page', DB::table('gsc_page_daily')->value('page'));

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_QUERY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame('q', DB::table('gsc_query_daily')->value('query'));
    }

    #[Test]
    public function pagination_resume_and_zero_final_page_are_idempotent(): void
    {
        config([
            'moxdop-gsc-collector.page_size' => 2,
            'moxdop-gsc-collector.max_pages_per_tick' => 1,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => function ($request) {
                $data = $request->data();
                if ($data === []) {
                    $data = json_decode($request->body(), true) ?? [];
                }
                $startRow = (int) ($data['startRow'] ?? 0);
                if ($startRow === 0) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['2026-08-01', 'a'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1],
                            ['keys' => ['2026-08-01', 'b'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1],
                        ],
                    ], 200);
                }
                if ($startRow === 2) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['2026-08-01', 'c'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1],
                        ],
                    ], 200);
                }

                return Http::response(['rows' => []], 200);
            },
        ]);

        [$context, $datasetRun] = $this->makeContext(SearchConsoleRequestFamilyCatalog::FAMILY_QUERY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $executor = app(SearchConsoleDatasetExecutor::class);

        $r1 = $executor->execute($context);
        $this->assertSame(DatasetExecutionOutcome::Continue, $r1->outcome);
        $this->assertSame(2, $r1->checkpoint['start_row']);
        app(CheckpointManager::class)->advance($datasetRun, $r1->checkpoint);

        $context2 = new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: $r1->checkpoint,
            registryDataset: $context->registryDataset,
            registryRequestFamily: $context->registryRequestFamily,
            attemptNumber: 2,
        );
        $r2 = $executor->execute($context2);
        $this->assertSame(DatasetExecutionOutcome::Completed, $r2->outcome);
        $this->assertSame(3, DB::table('gsc_query_daily')->count());

        // Crash-after-commit / replay same page: no duplicates.
        $replay = $executor->execute(new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: ['slice_index' => 0, 'start_row' => 0, 'pages_completed' => 0, 'rows_received_total' => 0, 'rows_written_total' => 0],
            registryDataset: $context->registryDataset,
            registryRequestFamily: $context->registryRequestFamily,
            attemptNumber: 3,
        ));
        $this->assertContains($replay->outcome, [DatasetExecutionOutcome::Continue, DatasetExecutionOutcome::Completed]);
        $this->assertSame(3, DB::table('gsc_query_daily')->count());
    }

    #[Test]
    public function high_cardinality_query_page_does_not_request_full_range_as_one_call(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []], 200),
        ]);

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_QUERY_PAGE_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-03']);

        $bodies = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data());
        $this->assertGreaterThanOrEqual(3, $bodies->count());
        foreach ($bodies as $body) {
            $this->assertSame($body['startDate'], $body['endDate']);
            $this->assertSame(['date', 'query', 'page'], $body['dimensions']);
        }
    }

    #[Test]
    public function rate_limit_maps_to_shared_retry_without_sleep(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'error' => ['message' => 'User rate limit exceeded'],
            ], 429, ['Retry-After' => '12']),
        ]);

        $result = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY);
        $this->assertSame(DatasetExecutionOutcome::Retry, $result->outcome);
        $this->assertSame(CollectionErrorCategory::RateLimit, $result->errorCategory);
        $this->assertSame(12, $result->backoffSeconds);
        $this->assertSame(0, DB::table('gsc_property_daily')->count());
    }

    #[Test]
    public function scope_failure_does_not_call_provider(): void
    {
        $this->integration->forceFill([
            'config' => ['granted_scopes' => ['https://www.googleapis.com/auth/analytics.readonly']],
        ])->save();

        Http::fake();

        $result = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame(CollectionErrorCategory::Authorization, $result->errorCategory);
        Http::assertNothingSent();
    }

    #[Test]
    public function sitemaps_ignore_deprecated_indexed_and_never_submit(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/sitemaps' => Http::response([
                'sitemap' => [[
                    'path' => 'https://example.com/sitemap.xml',
                    'lastSubmitted' => '2026-08-01T00:00:00z',
                    'lastDownloaded' => '2026-08-02T00:00:00z',
                    'isPending' => false,
                    'isSitemapsIndex' => false,
                    'type' => 'sitemap',
                    'warnings' => 1,
                    'errors' => 0,
                    'contents' => [[
                        'type' => 'web',
                        'submitted' => 120,
                        'indexed' => 999,
                    ]],
                ]],
            ], 200),
        ]);

        $result = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_SITEMAPS);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        $row = DB::table('gsc_sitemap_snapshot')->first();
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame(120, $meta['contents'][0]['submitted']);
        $this->assertTrue($meta['deprecated_indexed_used'] === false);
        $this->assertTrue($meta['sitemap_indexation_rate_created'] === false);
        $this->assertArrayNotHasKey('indexed', $meta['contents'][0]);

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertStringNotContainsString('submit', $request->url());
            $this->assertStringNotContainsString('delete', $request->url());
        }
    }

    #[Test]
    public function url_inspection_is_controlled_quota_aware_and_keeps_canonicals_separate(): void
    {
        config(['moxdop-gsc-collector.max_pages_per_tick' => 50]);

        Http::fake([
            'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect' => Http::response([
                'inspectionResult' => [
                    'inspectionResultLink' => 'https://search.google.com/search-console/inspect/fake',
                    'indexStatusResult' => [
                        'verdict' => 'PASS',
                        'coverageState' => 'Submitted and indexed',
                        'googleCanonical' => 'https://example.com/google-canon',
                        'userCanonical' => 'https://example.com/user-canon',
                        'indexingState' => 'INDEXING_ALLOWED',
                        'robotsTxtState' => 'ALLOWED',
                        'pageFetchState' => 'SUCCESSFUL',
                        'lastCrawlTime' => '2026-08-01T00:00:00Z',
                    ],
                ],
            ], 200),
        ]);

        $targets = array_map(fn (int $i): string => 'https://example.com/page-'.$i, range(1, 25));
        [$context, $datasetRun] = $this->makeContext(
            SearchConsoleRequestFamilyCatalog::FAMILY_URL_INSPECTION,
            null,
            ['url_inspection_targets' => $targets],
        );
        $executor = app(SearchConsoleDatasetExecutor::class);
        $result = $executor->execute($context);
        $guard = 0;
        while ($result->outcome === DatasetExecutionOutcome::Continue && $guard < 40) {
            $guard++;
            app(CheckpointManager::class)->advance($datasetRun, $result->checkpoint ?? []);
            $result = $executor->execute(new DatasetExecutionContext(
                collectionRun: $context->collectionRun->fresh(),
                resourceRun: $context->resourceRun->fresh(),
                datasetRun: $datasetRun->fresh(),
                checkpoint: $result->checkpoint ?? [],
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: $guard + 1,
            ));
        }

        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        $this->assertSame(25, DB::table('gsc_url_inspection_snapshot')->count());
        $this->assertSame(25, collect(Http::recorded())->count());

        $meta = json_decode((string) DB::table('gsc_url_inspection_snapshot')->first()->metadata, true);
        $this->assertSame('https://example.com/google-canon', $meta['google_canonical']);
        $this->assertSame('https://example.com/user-canon', $meta['user_canonical']);
        $this->assertFalse($meta['live_url_test_claimed']);
        $this->assertFalse($meta['site_wide_indexed_total_inferred']);
    }

    #[Test]
    public function inspection_outside_property_rejected_before_provider_call(): void
    {
        Http::fake();
        $result = $this->runFamily(
            SearchConsoleRequestFamilyCatalog::FAMILY_URL_INSPECTION,
            null,
            ['url_inspection_targets' => ['https://evil.example/other']],
        );
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('INSPECTION_PROPERTY_VALIDATION', $result->errorCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function zero_inspection_targets_completes_without_provider_calls(): void
    {
        Http::fake();
        $result = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_URL_INSPECTION);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        Http::assertNothingSent();
    }

    #[Test]
    public function natural_key_upsert_and_late_correction_across_runs(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push(['rows' => [
                    ['keys' => ['2026-08-01'], 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5],
                ]])
                ->push(['rows' => [
                    ['keys' => ['2026-08-01'], 'clicks' => 9, 'impressions' => 90, 'ctr' => 0.1, 'position' => 4],
                ]]),
        ]);

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(1, DB::table('gsc_property_daily')->count());
        $this->assertSame(9, (int) DB::table('gsc_property_daily')->value('clicks'));
    }

    #[Test]
    public function materialization_records_provider_limitation_not_universe_exhaustiveness(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [
                    ['keys' => ['2026-08-01'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1],
                ],
            ], 200),
        ]);

        $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);

        $mat = DatasetMaterialization::query()->where('dataset_id', 'gsc_property_daily')->first();
        $this->assertNotNull($mat);
        $this->assertFalse($mat->freshness_metadata['provider_universe_exhaustive']);
        $this->assertSame(SearchConsoleProviderCapabilities::PROVIDER_COMPLETENESS, $mat->freshness_metadata['provider_completeness']);
        $this->assertFalse($mat->freshness_metadata['missing_query_equals_zero']);
    }

    #[Test]
    public function sibling_isolation_query_page_failure_does_not_block_property_daily(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => function ($request) {
                $dims = $request->data()['dimensions'] ?? [];
                if ($dims === ['date', 'query', 'page']) {
                    return Http::response(['error' => ['message' => 'Quota exceeded for load']], 403);
                }

                return Http::response([
                    'rows' => [
                        ['keys' => ['2026-08-01'], 'clicks' => 2, 'impressions' => 20, 'ctr' => 0.1, 'position' => 3],
                    ],
                ], 200);
            },
            'https://www.googleapis.com/webmasters/v3/sites/*/sitemaps' => Http::response(['sitemap' => []], 200),
        ]);

        $property = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $queryPage = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_QUERY_PAGE_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $sitemaps = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_SITEMAPS);

        $this->assertSame(DatasetExecutionOutcome::Completed, $property->outcome);
        $this->assertSame(DatasetExecutionOutcome::Retry, $queryPage->outcome);
        $this->assertSame(CollectionErrorCategory::Quota, $queryPage->errorCategory);
        $this->assertSame(DatasetExecutionOutcome::Completed, $sitemaps->outcome);
        $this->assertSame(1, DB::table('gsc_property_daily')->count());
    }

    #[Test]
    public function raw_payload_has_no_tokens_and_executor_is_registered(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [
                    ['keys' => ['2026-08-01'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1],
                ],
            ], 200),
        ]);

        $result = $this->runFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, ($result->errorCode ?? '').': '.($result->errorMessage ?? ''));

        $raw = DB::table('raw_ingestion_objects')->first();
        $this->assertNotNull($raw, 'raw_ingestion_objects row missing; count='.DB::table('raw_ingestion_objects')->count());
        $encoded = json_encode($raw);
        $this->assertStringNotContainsString('gsc-access-token', (string) $encoded);
        $this->assertStringNotContainsString('gsc-refresh-token', (string) $encoded);
        $this->assertStringNotContainsString('Bearer', (string) $encoded);
        $meta = json_decode((string) $raw->metadata, true);
        $this->assertIsArray($meta);
        $this->assertArrayNotHasKey('access_token', $meta);
        $this->assertArrayNotHasKey('authorization', $meta);

        $executor = app(DatasetExecutorResolver::class)->resolve(
            CollectionDatasetRun::factory()->create(['request_family_id' => 'GSC_RF_PROPERTY_DAILY'])
        );
        $this->assertInstanceOf(SearchConsoleDatasetExecutor::class, $executor);
    }

    #[Test]
    public function provider_capabilities_verification_date_is_current_audit(): void
    {
        $this->assertSame('2026-08-13', SearchConsoleProviderCapabilities::VERIFICATION_DATE);
        $this->assertSame(25000, SearchConsoleProviderCapabilities::MAX_ROW_LIMIT);
    }

    /**
     * @param  array{start: string, end: string}|null  $dateRange
     * @param  array<string, mixed>  $context
     */
    private function runFamily(string $family, ?array $dateRange = null, array $context = []): DatasetExecutionResult
    {
        [$executionContext, $datasetRun] = $this->makeContext($family, $dateRange, $context);
        $executor = app(SearchConsoleDatasetExecutor::class);
        $result = $executor->execute($executionContext);
        $guard = 0;
        while ($result->outcome === DatasetExecutionOutcome::Continue && $guard < 40) {
            $guard++;
            if ($result->checkpoint !== null) {
                app(CheckpointManager::class)->advance($datasetRun, $result->checkpoint);
            }
            $result = $executor->execute(new DatasetExecutionContext(
                collectionRun: $executionContext->collectionRun->fresh(),
                resourceRun: $executionContext->resourceRun->fresh(),
                datasetRun: $datasetRun->fresh(),
                checkpoint: $result->checkpoint ?? [],
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: $guard + 1,
            ));
        }

        return $result;
    }

    /**
     * @param  array{start: string, end: string}|null  $dateRange
     * @param  array<string, mixed>  $extraContext
     * @param  array<string, mixed>  $checkpoint
     * @return array{0: DatasetExecutionContext, 1: CollectionDatasetRun}
     */
    private function makeContext(
        string $family,
        ?array $dateRange = null,
        array $extraContext = [],
        array $checkpoint = [],
    ): array {
        $dateRange ??= ['start' => '2026-08-01', 'end' => '2026-08-02'];

        $definition = SearchConsoleRequestFamilyCatalog::definition($family);
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->brand->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => [
                'date_range' => $dateRange,
                'context' => $extraContext,
            ],
        ]);

        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'external_resource_id' => $this->resource->id,
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => $this->binding->id,
            'status' => CollectionRunStatus::Running,
        ]);

        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'dataset_contract_id' => $definition['dataset_id'] ?? $family,
            'request_family_id' => $family,
            'contract_registry_version' => 1,
            'status' => CollectionRunStatus::Running,
        ]);

        $context = new DatasetExecutionContext(
            collectionRun: $run,
            resourceRun: $resourceRun,
            datasetRun: $datasetRun,
            checkpoint: $checkpoint,
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 1,
        );

        return [$context, $datasetRun];
    }
}
