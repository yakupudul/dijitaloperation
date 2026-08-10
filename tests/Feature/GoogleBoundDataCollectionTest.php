<?php

namespace Tests\Feature;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Services\Integrations\CollectLiveBoundDataService;
use App\Support\Integrations\ComparisonPeriod;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;
use MoxDop\Website\Collection\Ga4BoundCollector;
use MoxDop\Website\Collection\SearchConsoleBoundCollector;
use Tests\TestCase;

class GoogleBoundDataCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    private DigitalAsset $website;

    private DigitalAsset $adsAsset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.developer_token' => null,
            'moxdop.google.ads_api_version' => 'v25',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
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
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->website = DigitalAsset::factory()->create(['type' => 'website']);
        $this->adsAsset = DigitalAsset::factory()->create(['type' => 'google_ads']);
    }

    public function test_run_binding_provenance_and_comparison_period(): void
    {
        $periods = ComparisonPeriod::lastTwentyEightCompleteDays();
        $this->assertSame(28, $periods['complete_days']);
        $this->assertNotSame($periods['current']['start'], $periods['previous']['start']);

        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'external_id' => 'sc-domain:example.com',
            'display_name' => 'example.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*' => Http::response([
                'rows' => [[
                    'clicks' => 10,
                    'impressions' => 100,
                    'ctr' => 0.1,
                    'position' => 5.0,
                    'keys' => ['2026-07-01'],
                ]],
            ], 200),
        ]);

        $run = app(SearchConsoleBoundCollector::class)->collect($binding->fresh(['digitalAsset', 'externalResource.integration']));
        $this->assertSame($binding->id, $run->core_asset_binding_id);
        $this->assertNull($run->core_connection_id);
        $this->assertSame('website', $run->module_id);
        $this->assertDatabaseHas('evidence', [
            'run_id' => $run->id,
            'type' => 'gsc_performance_summary',
        ]);
    }

    public function test_disabled_binding_and_unavailable_resource_and_disabled_integration(): void
    {
        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $disabledBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_DISABLED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Binding is not active');
        app(SearchConsoleBoundCollector::class)->collect($disabledBinding);
    }

    public function test_authorization_failure_blocks_collection(): void
    {
        $this->integration->authorizationCredential()?->delete();
        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google authorization');
        app(SearchConsoleBoundCollector::class)->collect($binding->fresh(['digitalAsset', 'externalResource.integration']));
    }

    public function test_search_console_normalization_and_no_secrets_in_evidence(): void
    {
        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'external_id' => 'sc-domain:moximu.com',
            'display_name' => 'moximu.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*' => Http::response([
                'rows' => [[
                    'clicks' => 20,
                    'impressions' => 200,
                    'ctr' => 0.1,
                    'position' => 4.2,
                    'keys' => ['brand query'],
                ]],
            ], 200),
        ]);

        $run = app(SearchConsoleBoundCollector::class)->collect($binding->fresh(['digitalAsset', 'externalResource.integration']));
        $this->assertSame('completed', $run->status);
        $types = Evidence::query()->where('run_id', $run->id)->pluck('type')->sort()->values()->all();
        $this->assertSame([
            'gsc_daily_performance',
            'gsc_page_performance',
            'gsc_performance_summary',
            'gsc_query_page_performance',
            'gsc_query_performance',
        ], $types);

        $summary = Evidence::query()->where('run_id', $run->id)->where('type', 'gsc_performance_summary')->firstOrFail();
        $this->assertEquals(20, data_get($summary->payload, 'current.clicks'));
        $this->assertArrayHasKey('deltas', $summary->payload);
        $this->assertNull(data_get($summary->payload, 'access_token'));
        $this->assertNull(data_get($run->metadata, 'access_token'));
        $this->assertNull(data_get($run->metadata, 'refresh_token'));
        $this->assertSame(5, Evidence::query()->where('run_id', $run->id)->count());

        $queryPage = Evidence::query()->where('run_id', $run->id)->where('type', 'gsc_query_page_performance')->firstOrFail();
        $this->assertSame(['query', 'page'], data_get($queryPage->payload, 'dimensions'));
        $this->assertLessThanOrEqual(100, (int) data_get($queryPage->payload, 'row_limit'));
    }

    public function test_ga4_normalization_without_invented_events(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/123456',
            'display_name' => 'GA4 Property',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://analyticsdata.googleapis.com/*' => Http::response([
                'metricHeaders' => [
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'engagementRate'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'keyEvents'],
                ],
                'totals' => [[
                    'metricValues' => [
                        ['value' => '100'],
                        ['value' => '40'],
                        ['value' => '120'],
                        ['value' => '80'],
                        ['value' => '0.66'],
                        ['value' => '300'],
                        ['value' => '5'],
                    ],
                ]],
                'rows' => [],
            ], 200),
        ]);

        $run = app(Ga4BoundCollector::class)->collect($binding->fresh(['digitalAsset', 'externalResource.integration']));
        $this->assertSame('completed', $run->status);
        $summary = Evidence::query()->where('run_id', $run->id)->where('type', 'ga4_performance_summary')->firstOrFail();
        $this->assertFalse((bool) data_get($summary->payload, 'invented_events'));
        $this->assertEquals(100, data_get($summary->payload, 'current.totalUsers'));
        $this->assertStringContainsString('keyEvents', (string) data_get($summary->payload, 'key_events_note'));
        $this->assertDatabaseHas('evidence', ['run_id' => $run->id, 'type' => 'ga4_landing_page_performance']);
        $this->assertDatabaseHas('evidence', ['run_id' => $run->id, 'type' => 'ga4_acquisition_summary']);
    }

    public function test_ads_mcc_login_customer_id_and_landing_url_compatibility(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '2222222222',
            'display_name' => 'Panorama Ankara',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'login_customer_id' => '1111111111',
                'manager_customer_id' => '1111111111',
            ],
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->adsAsset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'googleAds:search')) {
                $this->assertSame('POST', $request->method());
                $this->assertSame('1111111111', $request->header('login-customer-id')[0] ?? null);
                $this->assertSame('dev-token', $request->header('developer-token')[0] ?? null);
                $body = $request->data();
                $query = strtolower((string) ($body['query'] ?? ''));
                $this->assertStringNotContainsString('mutate', $query);
                $this->assertStringNotContainsString('mutate', strtolower($request->url()));

                if (str_contains($query, 'ad_group_ad.ad.final_urls')) {
                    return Http::response([
                        'results' => [[
                            'adGroupAd' => [
                                'ad' => [
                                    'finalUrls' => ['https://panorama.example/landing'],
                                ],
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($query, 'from search_term_view')) {
                    return Http::response([
                        'results' => [[
                            'searchTermView' => [
                                'searchTerm' => 'brand shoes',
                                'status' => 'NONE',
                            ],
                            'campaign' => [
                                'id' => '99',
                                'name' => 'Brand',
                                'advertisingChannelType' => 'SEARCH',
                            ],
                            'adGroup' => [
                                'id' => '11',
                                'name' => 'Exact',
                            ],
                            'metrics' => [
                                'costMicros' => '40000000',
                                'impressions' => '800',
                                'clicks' => '40',
                                'conversions' => 0,
                                'conversionsValue' => 0,
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($query, 'from campaign_search_term_view')) {
                    return Http::response([
                        'results' => [[
                            'campaignSearchTermView' => [
                                'searchTerm' => 'pmax query',
                            ],
                            'campaign' => [
                                'id' => '77',
                                'name' => 'PMax',
                                'advertisingChannelType' => 'PERFORMANCE_MAX',
                            ],
                            'metrics' => [
                                'costMicros' => '10000000',
                                'impressions' => '200',
                                'clicks' => '10',
                                'conversions' => 1,
                                'conversionsValue' => 50,
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($query, 'from conversion_action')) {
                    return Http::response([
                        'results' => [[
                            'conversionAction' => [
                                'id' => '55',
                                'name' => 'Purchase',
                                'status' => 'ENABLED',
                                'type' => 'WEBPAGE',
                                'category' => 'PURCHASE',
                                'origin' => 'WEBSITE',
                                'primaryForGoal' => true,
                                'includeInConversionsMetric' => true,
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($query, 'from campaign')) {
                    return Http::response([
                        'results' => [[
                            'campaign' => [
                                'id' => '99',
                                'name' => 'Brand',
                                'status' => 'ENABLED',
                                'advertisingChannelType' => 'SEARCH',
                            ],
                            'metrics' => [
                                'costMicros' => '1500000',
                                'impressions' => '1000',
                                'clicks' => '50',
                                'ctr' => 0.05,
                                'conversions' => 2,
                                'conversionsValue' => 100,
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'results' => [[
                        'metrics' => [
                            'costMicros' => '2500000',
                            'impressions' => '2000',
                            'clicks' => '100',
                            'ctr' => 0.05,
                            'averageCpc' => '25000',
                            'conversions' => 3,
                            'conversionsValue' => 300,
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $run = app(GoogleAdsBoundCollector::class)->collect($binding->fresh(['digitalAsset', 'externalResource.integration']));
        $this->assertSame('completed', $run->status);
        $this->assertSame('1111111111', data_get($run->metadata, 'login_customer_id'));

        $summary = Evidence::query()->where('run_id', $run->id)->where('type', 'google_ads_account_summary')->firstOrFail();
        $this->assertSame(2.5, data_get($summary->payload, 'current.cost'));

        $landing = Evidence::query()->where('run_id', $run->id)->where('type', 'google_ads_landing_final_urls')->firstOrFail();
        $this->assertTrue((bool) data_get($landing->payload, 'ok'));
        $this->assertSame(['https://panorama.example/landing'], data_get($landing->payload, 'final_urls'));
        $this->assertSame('google_ads_search_gaql', data_get($landing->payload, 'fetch_method'));
        $this->assertNull(data_get($landing->payload, 'access_token'));

        $searchTerms = Evidence::query()->where('run_id', $run->id)->where('type', 'google_ads_search_term_performance')->firstOrFail();
        $this->assertTrue((bool) data_get($searchTerms->payload, 'response_ok'));
        $this->assertTrue((bool) data_get($searchTerms->payload, 'untrusted_text'));
        $this->assertGreaterThanOrEqual(2, (int) data_get($searchTerms->payload, 'row_count'));
        $this->assertSame(40.0, (float) data_get($searchTerms->payload, 'rows.0.cost'));
        $this->assertNull(data_get($searchTerms->payload, 'rows.1.ad_group_id'));
        $this->assertSame('campaign_search_term_view', data_get($searchTerms->payload, 'rows.1.source_report'));

        $conversions = Evidence::query()->where('run_id', $run->id)->where('type', 'google_ads_conversion_actions')->firstOrFail();
        $this->assertTrue((bool) data_get($conversions->payload, 'response_ok'));
        $this->assertSame(1, (int) data_get($conversions->payload, 'usable_primary_or_included_count'));
        $this->assertNull(data_get($conversions->payload, 'actions.0.tag_snippets'));
    }

    public function test_collect_live_data_orchestrator_and_registry(): void
    {
        $registry = app(BoundCollectorRegistry::class);
        $this->assertNotNull($registry->forCapability('search_console'));
        $this->assertNotNull($registry->forCapability('ga4'));
        $this->assertNotNull($registry->forCapability('google_ads'));
        $this->assertNotNull($registry->forCapability('google_business_profile'));

        $result = app(CollectLiveBoundDataService::class)->collect($this->website);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No active provider bindings', $result['message']);

        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'external_id' => 'sc-domain:moximu.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*' => Http::response(['rows' => []], 200),
        ]);

        $result = app(CollectLiveBoundDataService::class)->collect($this->website->fresh());
        $this->assertNotEmpty($result['runs']);
        $this->assertInstanceOf(Run::class, $result['runs'][0]);
    }

    public function test_token_refresh_path_on_google_api_client_post(): void
    {
        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'external_id' => 'sc-domain:moximu.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-atok',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://www.googleapis.com/webmasters/v3/sites/*' => Http::sequence()
                ->push(['error' => 'expired'], 401)
                ->push(['rows' => [['clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 3]]], 200)
                ->whenEmpty(Http::response(['rows' => []], 200)),
        ]);

        $run = app(SearchConsoleBoundCollector::class)->collect($binding->fresh(['digitalAsset', 'externalResource.integration']));
        $this->assertContains($run->status, ['completed', 'failed']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token'));
    }
}
