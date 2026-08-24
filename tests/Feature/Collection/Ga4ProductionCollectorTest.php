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
use App\Services\Collection\Providers\Ga4\Ga4DatasetExecutor;
use App\Services\Collection\Providers\Ga4\Ga4ProviderCapabilities;
use App\Services\Collection\Providers\Ga4\Ga4ReportRequestBuilder;
use App\Services\Collection\Providers\Ga4\Ga4RequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Ga4ProductionCollectorTest extends TestCase
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
        Cache::flush();

        Storage::fake('raw_ingestion');
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'dev',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
            'moxdop-ga4-collector.page_size' => 10000,
            'moxdop-ga4-collector.max_pages_per_tick' => 50,
            'moxdop-ga4-collector.metadata_cache_ttl_seconds' => 3600,
            'moxdop-ga4-collector.compatibility_cache_ttl_seconds' => 3600,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'ga4',
            'status' => DigitalAssetStatus::Active,
        ]);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::ANALYTICS_READONLY],
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
                'access_token' => 'ga4-access-token',
                'refresh_token' => 'ga4-refresh-token',
                'scope' => GoogleScopes::ANALYTICS_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'ga4',
            'external_id' => 'properties/123456',
            'display_name' => 'Example GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function planner_maps_ga4_and_does_not_plan_unbound_resources(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));
        $this->assertSame('GA4', $plan['resources'][0]['provider_or_source']);
        $families = array_column($plan['datasets'], 'request_family_id');
        $this->assertContains(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, $families);
        $this->assertContains(Ga4RequestFamilyCatalog::FAMILY_LANDING_PAGE_DAILY, $families);

        $this->binding->forceFill(['status' => CoreAssetBinding::STATUS_DISABLED])->save();
        $this->expectException(\InvalidArgumentException::class);
        app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));
    }

    #[Test]
    public function property_metadata_persists_timezone_without_stream_as_root(): void
    {
        $this->fakeGa4Http();
        $result = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_METADATA);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $row = DB::table('ga4_property_metadata')->first();
        $this->assertNotNull($row);
        $this->assertSame('123456', $row->property_id);
        $this->assertSame('Europe/Istanbul', $row->source_timezone);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertTrue($meta['data_stream_is_not_collection_root']);
        $this->assertSame('G-TEST123', $meta['data_streams'][0]['webStreamData']['measurementId']);
    }

    #[Test]
    public function property_daily_preserves_property_timezone_and_does_not_rebucket(): void
    {
        $this->fakeGa4Http([
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date']],
                'metricHeaders' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'userEngagementDuration'],
                    ['name' => 'totalUsers'],
                    ['name' => 'activeUsers'],
                ],
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801']],
                    'metricValues' => [
                        ['value' => '10'],
                        ['value' => '7'],
                        ['value' => '20'],
                        ['value' => '120'],
                        ['value' => '5'],
                        ['value' => '4'],
                    ],
                ]],
                'rowCount' => 1,
            ],
        ]);

        $result = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $row = DB::table('ga4_property_daily')->first();
        $this->assertSame('2026-08-01', $row->reporting_date);
        $this->assertSame('Europe/Istanbul', $row->source_timezone);
        $this->assertSame(10, (int) $row->sessions);

        $request = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), 'runReport'));
        $this->assertNotNull($request);
        $body = $request[0]->data();
        $this->assertSame([['name' => 'date']], $body['dimensions']);
        $this->assertFalse($body['keepEmptyRows']);
        $this->assertArrayNotHasKey('access_token', $body);
    }

    #[Test]
    public function property_daily_persists_optional_metrics_when_property_metadata_supports_them(): void
    {
        $allMetrics = Ga4RequestFamilyCatalog::propertyDailyAllMetrics();
        $this->fakeGa4Http([
            'metadata' => [
                'dimensions' => array_map(static fn (string $n): array => ['apiName' => $n], ['date']),
                'metrics' => array_map(static fn (string $n): array => ['apiName' => $n], $allMetrics),
            ],
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date']],
                'metricHeaders' => array_map(static fn (string $name): array => ['name' => $name], $allMetrics),
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801']],
                    'metricValues' => [
                        ['value' => '10'], ['value' => '7'], ['value' => '20'], ['value' => '120'],
                        ['value' => '5'], ['value' => '4'], ['value' => '3'], ['value' => '2'],
                        ['value' => '2'], ['value' => '12.5'],
                    ],
                ]],
                'rowCount' => 1,
            ],
        ]);

        $result = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $row = DB::table('ga4_property_daily')->first();
        $this->assertSame(3, (int) $row->newUsers);
        $this->assertSame(2, (int) $row->conversions);
        $this->assertSame(2, (int) $row->keyEvents);
        $this->assertEqualsWithDelta(12.5, (float) $row->totalRevenue, 0.001);
    }

    #[Test]
    public function session_acquisition_scopes_are_not_first_user(): void
    {
        $this->fakeGa4Http([
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date'], ['name' => 'sessionDefaultChannelGroup']],
                'metricHeaders' => [['name' => 'sessions'], ['name' => 'engagedSessions']],
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801'], ['value' => 'Organic Search']],
                    'metricValues' => [['value' => '3'], ['value' => '2']],
                ]],
                'rowCount' => 1,
            ],
        ]);

        $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_CHANNEL_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $body = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), 'runReport'))[0]->data();
        $dimNames = array_column($body['dimensions'], 'name');
        $this->assertSame(['date', 'sessionDefaultChannelGroup'], $dimNames);
        $this->assertNotContains('firstUserDefaultChannelGroup', $dimNames);
        $this->assertSame('Organic Search', DB::table('ga4_acquisition_channel_daily')->value('sessionDefaultChannelGroup'));
    }

    #[Test]
    public function source_medium_splits_into_scoped_storage_columns(): void
    {
        $this->fakeGa4Http([
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date'], ['name' => 'sessionSourceMedium']],
                'metricHeaders' => [['name' => 'sessions'], ['name' => 'engagedSessions']],
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801'], ['value' => 'google / organic']],
                    'metricValues' => [['value' => '8'], ['value' => '5']],
                ]],
                'rowCount' => 1,
            ],
        ]);

        $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_SOURCE_MEDIUM_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $row = DB::table('ga4_source_medium_daily')->first();
        $this->assertSame('google', $row->sessionSource);
        $this->assertSame('organic', $row->sessionMedium);
        $this->assertSame(5, (int) $row->engagedSessions);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame('session_acquisition', $meta['semantic_scope']);
    }

    #[Test]
    public function landing_page_preserves_provider_value_without_url_rewrite(): void
    {
        $this->fakeGa4Http([
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date'], ['name' => 'landingPage']],
                'metricHeaders' => [['name' => 'sessions'], ['name' => 'engagedSessions']],
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801'], ['value' => '/pricing?utm=1']],
                    'metricValues' => [['value' => '2'], ['value' => '1']],
                ]],
                'rowCount' => 1,
            ],
        ]);

        $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_LANDING_PAGE_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $body = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), 'runReport'))[0]->data();
        $this->assertSame(['date', 'landingPage'], array_column($body['dimensions'], 'name'));
        $this->assertNotContains('landingPagePlusQueryString', array_column($body['dimensions'], 'name'));
        $this->assertSame('/pricing?utm=1', DB::table('ga4_landing_page_daily')->value('landingPage'));
    }

    #[Test]
    public function postgres_partitioned_ga4_tables_keep_contract_column_identifiers_after_repair(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL');
        }

        $expected = [
            'ga4_source_medium_daily' => ['sessionSource', 'sessionMedium', 'engagedSessions'],
            'ga4_campaign_daily' => ['sessionCampaignName', 'engagedSessions'],
            'ga4_landing_page_daily' => ['landingPage', 'engagedSessions'],
            'ga4_event_daily' => ['eventName', 'eventCount'],
            'ga4_event_channel_daily' => ['eventName', 'sessionDefaultChannelGroup', 'eventCount'],
            'ga4_event_campaign_daily' => ['eventName', 'sessionCampaignName', 'eventCount'],
            'ga4_event_landing_daily' => ['eventName', 'landingPage', 'eventCount'],
        ];

        foreach ($expected as $table => $columns) {
            $actual = collect(DB::select(
                'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
                ['public', $table]
            ))->pluck('column_name')->all();

            foreach ($columns as $column) {
                $this->assertContains($column, $actual, "{$table} is missing {$column}");
            }
        }
    }

    #[Test]
    public function events_are_provider_facts_without_business_action_mapping(): void
    {
        $this->fakeGa4Http([
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date'], ['name' => 'eventName']],
                'metricHeaders' => [['name' => 'eventCount']],
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801'], ['value' => 'generate_lead']],
                    'metricValues' => [['value' => '17']],
                ]],
                'rowCount' => 1,
            ],
        ]);

        $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_EVENT_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $row = DB::table('ga4_event_daily')->first();
        $this->assertSame('generate_lead', $row->eventName);
        $this->assertSame(17, (int) $row->eventCount);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertFalse($meta['business_action_mapping_applied']);
        $this->assertFalse($meta['key_event_is_business_outcome']);
        // Mapping is DOMAIN_DATA / not applied by collector — no Business Action rows invented.
        $this->assertSame(0, DB::table('ga4_event_daily')->where('eventName', 'generate_lead')->where('eventCount', 0)->count());
    }

    #[Test]
    public function pagination_resume_uses_offset_and_is_idempotent(): void
    {
        config([
            'moxdop-ga4-collector.page_size' => 2,
            'moxdop-ga4-collector.max_pages_per_tick' => 1,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/metadata')) {
                return Http::response($this->metadataPayload(), 200);
            }
            if (str_contains($url, 'checkCompatibility')) {
                return Http::response(['dimensionCompatibilities' => [], 'metricCompatibilities' => []], 200);
            }
            if (str_contains($url, 'dataStreams')) {
                return Http::response(['dataStreams' => []], 200);
            }
            if (str_contains($url, 'analyticsadmin') && str_contains($url, 'properties/')) {
                return Http::response($this->adminPropertyPayload(), 200);
            }
            if (str_contains($url, 'runReport')) {
                $data = $request->data() ?: (json_decode($request->body(), true) ?? []);
                $offset = (int) ($data['offset'] ?? 0);
                if ($offset === 0) {
                    return Http::response([
                        'dimensionHeaders' => [['name' => 'date'], ['name' => 'eventName']],
                        'metricHeaders' => [['name' => 'eventCount']],
                        'rows' => [
                            ['dimensionValues' => [['value' => '20260801'], ['value' => 'a']], 'metricValues' => [['value' => '1']]],
                            ['dimensionValues' => [['value' => '20260801'], ['value' => 'b']], 'metricValues' => [['value' => '1']]],
                        ],
                        'rowCount' => 3,
                    ], 200);
                }
                if ($offset === 2) {
                    return Http::response([
                        'dimensionHeaders' => [['name' => 'date'], ['name' => 'eventName']],
                        'metricHeaders' => [['name' => 'eventCount']],
                        'rows' => [
                            ['dimensionValues' => [['value' => '20260801'], ['value' => 'c']], 'metricValues' => [['value' => '1']]],
                        ],
                        'rowCount' => 3,
                    ], 200);
                }

                return Http::response([
                    'dimensionHeaders' => [['name' => 'date'], ['name' => 'eventName']],
                    'metricHeaders' => [['name' => 'eventCount']],
                    'rows' => [],
                    'rowCount' => 3,
                ], 200);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });

        [$context, $datasetRun] = $this->makeContext(Ga4RequestFamilyCatalog::FAMILY_EVENT_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $executor = app(Ga4DatasetExecutor::class);
        $r1 = $executor->execute($context);
        $this->assertSame(DatasetExecutionOutcome::Continue, $r1->outcome);
        $this->assertSame(2, $r1->checkpoint['offset']);
        app(CheckpointManager::class)->advance($datasetRun, $r1->checkpoint);

        $r2 = $executor->execute(new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: $r1->checkpoint,
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 2,
        ));
        $this->assertSame(DatasetExecutionOutcome::Completed, $r2->outcome);
        $this->assertSame(3, DB::table('ga4_event_daily')->count());

        // Replay page 1 — no duplicates.
        $executor->execute(new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: ['slice_index' => 0, 'offset' => 0],
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 3,
        ));
        $this->assertSame(3, DB::table('ga4_event_daily')->count());
    }

    #[Test]
    public function required_incompatible_metric_fails_without_mutating_request(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/properties/*' => Http::response($this->adminPropertyPayload(), 200),
            'https://analyticsadmin.googleapis.com/v1beta/properties/*/dataStreams' => Http::response(['dataStreams' => []], 200),
            'https://analyticsdata.googleapis.com/v1beta/properties/*/metadata' => Http::response([
                'dimensions' => [['apiName' => 'date']],
                'metrics' => [['apiName' => 'sessions']], // missing others
            ], 200),
            'https://analyticsdata.googleapis.com/v1beta/properties/*:checkCompatibility' => Http::response([], 200),
            'https://analyticsdata.googleapis.com/v1beta/properties/*:runReport' => Http::response(['rows' => []], 200),
        ]);

        $result = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame(CollectionErrorCategory::ContractMismatch, $result->errorCategory);
        $this->assertSame('PROVIDER_INCOMPATIBLE', $result->errorCode);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'runReport'));
    }

    #[Test]
    public function compatibility_is_cached_across_pages(): void
    {
        config([
            'moxdop-ga4-collector.page_size' => 1,
            'moxdop-ga4-collector.max_pages_per_tick' => 50,
        ]);

        $compatCalls = 0;
        Http::fake(function ($request) use (&$compatCalls) {
            $url = $request->url();
            if (str_contains($url, 'checkCompatibility')) {
                $compatCalls++;

                return Http::response(['dimensionCompatibilities' => [], 'metricCompatibilities' => []], 200);
            }
            if (str_contains($url, '/metadata')) {
                return Http::response($this->metadataPayload(), 200);
            }
            if (str_contains($url, 'dataStreams')) {
                return Http::response(['dataStreams' => []], 200);
            }
            if (str_contains($url, 'analyticsadmin')) {
                return Http::response($this->adminPropertyPayload(), 200);
            }
            if (str_contains($url, 'runReport')) {
                $data = $request->data() ?: (json_decode($request->body(), true) ?? []);
                $offset = (int) ($data['offset'] ?? 0);
                if ($offset === 0) {
                    return Http::response([
                        'dimensionHeaders' => [['name' => 'date'], ['name' => 'deviceCategory']],
                        'metricHeaders' => [['name' => 'sessions'], ['name' => 'engagedSessions']],
                        'rows' => [[
                            'dimensionValues' => [['value' => '20260801'], ['value' => 'desktop']],
                            'metricValues' => [['value' => '1'], ['value' => '1']],
                        ]],
                        'rowCount' => 2,
                    ], 200);
                }

                return Http::response([
                    'dimensionHeaders' => [['name' => 'date'], ['name' => 'deviceCategory']],
                    'metricHeaders' => [['name' => 'sessions'], ['name' => 'engagedSessions']],
                    'rows' => [[
                        'dimensionValues' => [['value' => '20260801'], ['value' => 'mobile']],
                        'metricValues' => [['value' => '1'], ['value' => '1']],
                    ]],
                    'rowCount' => 2,
                ], 200);
            }

            return Http::response(['error' => ['message' => 'bad']], 500);
        });

        $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_DEVICE_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(1, $compatCalls);
        $this->assertSame(2, DB::table('ga4_device_daily')->count());
    }

    #[Test]
    public function scope_failure_does_not_call_provider(): void
    {
        $this->integration->forceFill([
            'config' => ['granted_scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY]],
        ])->save();
        Http::fake();
        $result = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame(CollectionErrorCategory::Authorization, $result->errorCategory);
        Http::assertNothingSent();
    }

    #[Test]
    public function rate_limit_maps_to_shared_retry(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/properties/*' => Http::response($this->adminPropertyPayload(), 200),
            'https://analyticsadmin.googleapis.com/v1beta/properties/*/dataStreams' => Http::response(['dataStreams' => []], 200),
            'https://analyticsdata.googleapis.com/v1beta/properties/*/metadata' => Http::response($this->metadataPayload(), 200),
            'https://analyticsdata.googleapis.com/v1beta/properties/*:checkCompatibility' => Http::response([], 200),
            'https://analyticsdata.googleapis.com/v1beta/properties/*:runReport' => Http::response([
                'error' => ['message' => 'Resource exhausted'],
            ], 429, ['Retry-After' => '15']),
        ]);

        $result = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Retry, $result->outcome);
        $this->assertSame(CollectionErrorCategory::RateLimit, $result->errorCategory);
        $this->assertSame(15, $result->backoffSeconds);
    }

    #[Test]
    public function zero_row_success_and_materialization_limitation(): void
    {
        $this->fakeGa4Http([
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date']],
                'metricHeaders' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'userEngagementDuration'],
                    ['name' => 'totalUsers'],
                    ['name' => 'activeUsers'],
                ],
                'rows' => [],
                'rowCount' => 0,
            ],
        ]);

        $result = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        $this->assertSame(0, DB::table('ga4_property_daily')->count());
        $mat = DatasetMaterialization::query()->where('dataset_id', 'ga4_property_daily')->first();
        $this->assertNotNull($mat);
        $this->assertFalse($mat->freshness_metadata['missing_row_equals_zero']);
        $this->assertSame('Europe/Istanbul', $mat->freshness_metadata['property_timezone']);
    }

    #[Test]
    public function late_correction_upserts_natural_key(): void
    {
        $propertyDailyReport = static function (string $sessions): array {
            return [
                'dimensionHeaders' => [['name' => 'date']],
                'metricHeaders' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'userEngagementDuration'],
                    ['name' => 'totalUsers'],
                    ['name' => 'activeUsers'],
                ],
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801']],
                    'metricValues' => [
                        ['value' => $sessions], ['value' => '1'], ['value' => '1'],
                        ['value' => '1'], ['value' => '1'], ['value' => '1'],
                    ],
                ]],
                'rowCount' => 1,
            ];
        };

        // Single fake + sequence: Laravel Http::fake merges stubs; first match wins.
        $runReportSequence = Http::sequence()
            ->push($propertyDailyReport('1'))
            ->push($propertyDailyReport('99'));

        Http::swap(new Factory);
        Http::fake(function ($request) use ($runReportSequence) {
            $url = $request->url();
            if (str_contains($url, '/metadata')) {
                return Http::response($this->metadataPayload(), 200);
            }
            if (str_contains($url, 'checkCompatibility')) {
                return Http::response(['dimensionCompatibilities' => [], 'metricCompatibilities' => []], 200);
            }
            if (str_contains($url, 'dataStreams')) {
                return Http::response([
                    'dataStreams' => [[
                        'name' => 'properties/123456/dataStreams/1',
                        'type' => 'WEB_DATA_STREAM',
                        'displayName' => 'Web',
                        'webStreamData' => [
                            'measurementId' => 'G-TEST123',
                            'defaultUri' => 'https://example.com',
                        ],
                    ]],
                ], 200);
            }
            if (str_contains($url, 'analyticsadmin.googleapis.com') && str_contains($url, 'properties/')) {
                return Http::response($this->adminPropertyPayload(), 200);
            }
            if (str_contains($url, 'runReport')) {
                return $runReportSequence($request);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });

        $first = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $first->outcome);
        $this->assertSame(1, (int) DB::table('ga4_property_daily')->value('sessions'));

        Cache::flush();
        $second = $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $second->outcome, (string) $second->errorMessage);
        $this->assertSame(1, DB::table('ga4_property_daily')->count());
        $this->assertSame(99, (int) DB::table('ga4_property_daily')->value('sessions'));
    }

    #[Test]
    public function raw_payload_has_no_tokens_and_executor_registered(): void
    {
        $this->fakeGa4Http([
            'runReport' => [
                'dimensionHeaders' => [['name' => 'date']],
                'metricHeaders' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'userEngagementDuration'],
                    ['name' => 'totalUsers'],
                    ['name' => 'activeUsers'],
                ],
                'rows' => [[
                    'dimensionValues' => [['value' => '20260801']],
                    'metricValues' => [
                        ['value' => '1'], ['value' => '1'], ['value' => '1'],
                        ['value' => '1'], ['value' => '1'], ['value' => '1'],
                    ],
                ]],
                'rowCount' => 1,
            ],
        ]);
        $this->runFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $raw = DB::table('raw_ingestion_objects')->first();
        $this->assertNotNull($raw);
        $encoded = json_encode($raw);
        $this->assertStringNotContainsString('ga4-access-token', (string) $encoded);
        $this->assertStringNotContainsString('ga4-refresh-token', (string) $encoded);

        $executor = app(DatasetExecutorResolver::class)->resolve(
            CollectionDatasetRun::factory()->create(['request_family_id' => 'GA4_RF_PROPERTY_DAILY'])
        );
        $this->assertInstanceOf(Ga4DatasetExecutor::class, $executor);
        $this->assertSame('2026-08-13', Ga4ProviderCapabilities::VERIFICATION_DATE);
    }

    #[Test]
    public function forbidden_first_user_dimensions_are_rejected_by_request_builder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(Ga4ReportRequestBuilder::class)->build(
            ['date', 'firstUserSource'],
            ['sessions'],
            '2026-08-01',
            '2026-08-01',
            0,
            10,
            false,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGa4Http(array $overrides = []): void
    {
        $runReport = $overrides['runReport'] ?? [
            'dimensionHeaders' => [['name' => 'date']],
            'metricHeaders' => [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'screenPageViews'],
                ['name' => 'userEngagementDuration'],
                ['name' => 'totalUsers'],
                ['name' => 'activeUsers'],
            ],
            'rows' => [],
            'rowCount' => 0,
        ];
        $metadata = $overrides['metadata'] ?? $this->metadataPayload();

        // Replace factory: Http::fake() merges stub callbacks and first match wins.
        Http::swap(new Factory);

        Http::fake(function ($request) use ($runReport, $metadata) {
            $url = $request->url();
            if (str_contains($url, '/metadata')) {
                return Http::response($metadata, 200);
            }
            if (str_contains($url, 'checkCompatibility')) {
                return Http::response(['dimensionCompatibilities' => [], 'metricCompatibilities' => []], 200);
            }
            if (str_contains($url, 'dataStreams')) {
                return Http::response([
                    'dataStreams' => [[
                        'name' => 'properties/123456/dataStreams/1',
                        'type' => 'WEB_DATA_STREAM',
                        'displayName' => 'Web',
                        'webStreamData' => [
                            'measurementId' => 'G-TEST123',
                            'defaultUri' => 'https://example.com',
                        ],
                    ]],
                ], 200);
            }
            if (str_contains($url, 'analyticsadmin.googleapis.com') && str_contains($url, 'properties/')) {
                return Http::response($this->adminPropertyPayload(), 200);
            }
            if (str_contains($url, 'runReport')) {
                return Http::response($runReport, 200);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPropertyPayload(): array
    {
        return [
            'name' => 'properties/123456',
            'displayName' => 'Example GA4',
            'timeZone' => 'Europe/Istanbul',
            'currencyCode' => 'TRY',
            'propertyType' => 'PROPERTY_TYPE_ORDINARY',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataPayload(): array
    {
        $dims = [
            'date', 'sessionDefaultChannelGroup', 'sessionSourceMedium', 'sessionCampaignName',
            'landingPage', 'eventName', 'deviceCategory',
        ];
        $metrics = [
            'sessions', 'engagedSessions', 'screenPageViews', 'userEngagementDuration',
            'totalUsers', 'activeUsers', 'eventCount',
        ];

        return [
            'dimensions' => array_map(static fn (string $n): array => ['apiName' => $n], $dims),
            'metrics' => array_map(static fn (string $n): array => ['apiName' => $n], $metrics),
        ];
    }

    /**
     * @param  array{start: string, end: string}|null  $dateRange
     * @param  array<string, mixed>  $extraContext
     */
    private function runFamily(string $family, ?array $dateRange = null, array $extraContext = []): DatasetExecutionResult
    {
        [$executionContext, $datasetRun] = $this->makeContext($family, $dateRange, $extraContext);
        $executor = app(Ga4DatasetExecutor::class);
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
    private function makeContext(string $family, ?array $dateRange = null, array $extraContext = [], array $checkpoint = []): array
    {
        $dateRange ??= ['start' => '2026-08-01', 'end' => '2026-08-02'];
        $definition = Ga4RequestFamilyCatalog::definition($family);

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
            'provider_or_source' => 'GA4',
            'external_resource_id' => $this->resource->id,
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => $this->binding->id,
            'status' => CollectionRunStatus::Running,
        ]);

        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'GA4',
            'dataset_contract_id' => $definition['dataset_id'] ?? $family,
            'request_family_id' => $family,
            'contract_registry_version' => 1,
            'status' => CollectionRunStatus::Running,
        ]);

        return [
            new DatasetExecutionContext(
                collectionRun: $run,
                resourceRun: $resourceRun,
                datasetRun: $datasetRun,
                checkpoint: $checkpoint,
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: 1,
            ),
            $datasetRun,
        ];
    }
}
