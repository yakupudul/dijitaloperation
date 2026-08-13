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
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsDatasetExecutor;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsNormalizer;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsProductionCollectorTest extends TestCase
{
    use RefreshDatabase;

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
            'moxdop.google.developer_token' => 'app-level-dev-token',
            'moxdop.google.ads_api_version' => 'v25',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
            'moxdop-google-ads-collector.write_batch_size' => 100,
            'moxdop-google-ads-collector.max_search_pages_per_tick' => 50,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::ADWORDS],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'should-not-appear-in-raw',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'ads-access-token',
                'refresh_token' => 'ads-refresh-token',
                'scope' => GoogleScopes::ADWORDS,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '1112223333',
            'display_name' => 'Example Ads',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'is_manager' => false,
                'login_customer_id' => '9998887777',
                'currency_code' => 'EUR',
                'time_zone' => 'Europe/Berlin',
            ],
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function planner_maps_google_ads_and_rejects_unbound(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));
        $this->assertSame('GOOGLE_ADS', $plan['resources'][0]['provider_or_source']);
        $families = array_column($plan['datasets'], 'request_family_id');
        $this->assertContains(GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY, $families);
        $this->assertContains(GoogleAdsRequestFamilyCatalog::FAMILY_SEARCH_TERM, $families);

        $this->binding->forceFill(['status' => CoreAssetBinding::STATUS_DISABLED])->save();
        $this->expectException(\InvalidArgumentException::class);
        app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));
    }

    #[Test]
    public function manager_account_is_not_performance_root(): void
    {
        $this->resource->forceFill([
            'metadata' => array_merge($this->resource->metadata ?? [], ['is_manager' => true]),
        ])->save();

        $this->fakeAdsHttp();
        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('MANAGER_NOT_PERFORMANCE_ROOT', $result->errorCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function customer_metadata_preserves_timezone_currency_and_rejects_token_leak(): void
    {
        $this->fakeAdsHttp([
            'customer' => [
                'results' => [[
                    'customer' => [
                        'id' => '1112223333',
                        'descriptiveName' => 'Example Ads',
                        'currencyCode' => 'EUR',
                        'timeZone' => 'Europe/Berlin',
                        'manager' => false,
                        'testAccount' => false,
                        'autoTaggingEnabled' => true,
                    ],
                ]],
            ],
        ]);

        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);

        $row = DB::table('google_ads_account_snapshot')->first();
        $this->assertNotNull($row);
        $this->assertSame('1112223333', $row->customer_id);
        $this->assertSame('Europe/Berlin', $row->source_timezone);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame('EUR', $meta['currency_code']);
        $this->assertFalse($meta['manager']);

        $this->assertGreaterThan(0, DB::table('google_ads_campaign_snapshot')->count());
        $this->assertGreaterThan(0, DB::table('google_ads_conversion_action_snapshot')->count());

        foreach (DB::table('raw_ingestion_objects')->get() as $raw) {
            $payload = json_encode($raw);
            $this->assertStringNotContainsString('ads-access-token', (string) $payload);
            $this->assertStringNotContainsString('ads-refresh-token', (string) $payload);
            $this->assertStringNotContainsString('app-level-dev-token', (string) $payload);
            $this->assertStringNotContainsString('should-not-appear-in-raw', (string) $payload);
        }
    }

    #[Test]
    public function cost_micros_normalize_exactly_without_float(): void
    {
        $n = new GoogleAdsNormalizer;
        $this->assertSame('12.345678', $n->microsToAmount(12345678));
        $this->assertSame('0.000001', $n->microsToAmount(1));
        $this->assertSame('1.000000', $n->microsToAmount(1000000));
    }

    #[Test]
    public function campaign_daily_preserves_customer_timezone_and_currency(): void
    {
        $this->fakeAdsHttp([
            'searchStream' => [
                'results' => [[
                    'segments' => ['date' => '2026-08-01'],
                    'campaign' => [
                        'id' => '555',
                        'name' => 'Search Brand',
                        'status' => 'ENABLED',
                        'advertisingChannelType' => 'SEARCH',
                    ],
                    'metrics' => [
                        'impressions' => '100',
                        'clicks' => '10',
                        'costMicros' => '2500000',
                        'conversions' => 2,
                        'searchImpressionShare' => '0.450000',
                    ],
                ]],
                'requestId' => 'req-campaign-1',
            ],
        ]);

        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);

        $row = DB::table('google_ads_campaign_daily')->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-08-01', $row->reporting_date);
        $this->assertSame('Europe/Berlin', $row->source_timezone);
        $this->assertSame('EUR', $row->currency);
        $this->assertSame(2500000, (int) $row->cost_micros);
        $this->assertEqualsWithDelta(2.5, (float) $row->cost_amount, 0.000001);
        $this->assertSame('555', $row->campaign_id);
    }

    #[Test]
    public function typed_conversions_remain_separate_and_are_not_business_outcomes(): void
    {
        $this->fakeAdsHttp([
            'conversion_action' => [
                'results' => [
                    ['conversionAction' => [
                        'id' => '10', 'name' => 'Lead Form', 'status' => 'ENABLED',
                        'type' => 'WEBPAGE', 'category' => 'SUBMIT_LEAD_FORM',
                        'origin' => 'WEBSITE', 'primaryForGoal' => true,
                        'includeInConversionsMetric' => true, 'countingType' => 'ONE_PER_CLICK',
                    ]],
                    ['conversionAction' => [
                        'id' => '11', 'name' => 'Phone Call', 'status' => 'ENABLED',
                        'type' => 'PHONE_CALL_LEAD', 'category' => 'PHONE_CALL_LEAD',
                        'origin' => 'WEBSITE', 'primaryForGoal' => false,
                        'includeInConversionsMetric' => true, 'countingType' => 'ONE_PER_CLICK',
                    ]],
                ],
            ],
            'conversion_daily' => [
                'results' => [
                    [
                        'segments' => [
                            'date' => '2026-08-01',
                            'conversionAction' => 'customers/1112223333/conversionActions/10',
                            'conversionActionName' => 'Lead Form',
                            'conversionActionCategory' => 'SUBMIT_LEAD_FORM',
                        ],
                        'metrics' => ['conversions' => 10, 'conversionsValue' => '0', 'allConversions' => 12],
                    ],
                    [
                        'segments' => [
                            'date' => '2026-08-01',
                            'conversionAction' => 'customers/1112223333/conversionActions/11',
                            'conversionActionName' => 'Phone Call',
                            'conversionActionCategory' => 'PHONE_CALL_LEAD',
                        ],
                        'metrics' => ['conversions' => 4, 'conversionsValue' => '0', 'allConversions' => 4],
                    ],
                ],
            ],
        ]);

        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_CONVERSION_ACTION, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);

        $this->assertSame(2, DB::table('google_ads_conversion_action_snapshot')->count());
        $daily = DB::table('google_ads_conversion_action_daily')->orderBy('conversion_action_id')->get();
        $this->assertCount(2, $daily);
        $this->assertSame(10, (int) $daily[0]->conversions);
        $this->assertSame(4, (int) $daily[1]->conversions);
        $this->assertNotSame((int) $daily[0]->conversions + (int) $daily[1]->conversions, (int) $daily[0]->conversions);

        $meta = json_decode((string) $daily[0]->metadata, true);
        $this->assertTrue($meta['conversion_neq_business_outcome']);
        $this->assertTrue($meta['conversions_neq_all_conversions']);
        $this->assertFalse($meta['business_action_mapping_applied']);
        $this->assertSame(12, (int) $daily[0]->all_conversions);
    }

    #[Test]
    public function search_term_and_keyword_remain_distinct_and_pmax_uses_separate_view(): void
    {
        $this->fakeAdsHttp([
            'searchStream' => function ($request) {
                $q = $request->data()['query'] ?? '';
                if (str_contains($q, 'FROM search_term_view')) {
                    return Http::response(['results' => [[
                        'segments' => ['date' => '2026-08-01'],
                        'searchTermView' => ['searchTerm' => 'dental implants', 'status' => 'NONE'],
                        'campaign' => ['id' => '1', 'advertisingChannelType' => 'SEARCH'],
                        'adGroup' => ['id' => '2'],
                        'metrics' => ['impressions' => 5, 'clicks' => 1, 'costMicros' => '1000000', 'conversions' => 0],
                    ]]], 200);
                }
                if (str_contains($q, 'FROM campaign_search_term_view')) {
                    return Http::response(['results' => [[
                        'segments' => ['date' => '2026-08-01'],
                        'campaignSearchTermView' => ['searchTerm' => 'pmax term'],
                        'campaign' => ['id' => '9', 'advertisingChannelType' => 'PERFORMANCE_MAX'],
                        'metrics' => ['impressions' => 3, 'clicks' => 1, 'costMicros' => '500000', 'conversions' => 0],
                    ]]], 200);
                }
                if (str_contains($q, 'FROM keyword_view')) {
                    return Http::response(['results' => [[
                        'segments' => ['date' => '2026-08-01'],
                        'adGroupCriterion' => [
                            'criterionId' => '777',
                            'status' => 'ENABLED',
                            'keyword' => ['text' => 'dental implants', 'matchType' => 'EXACT'],
                        ],
                        'adGroup' => ['id' => '2'],
                        'campaign' => ['id' => '1'],
                        'metrics' => ['impressions' => 8, 'clicks' => 2, 'costMicros' => '2000000', 'conversions' => 1],
                    ]]], 200);
                }

                return Http::response(['results' => []], 200);
            },
        ]);

        $terms = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_SEARCH_TERM, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $terms->outcome, (string) $terms->errorMessage);
        $this->assertSame(2, DB::table('google_ads_search_term_daily')->count());
        $standard = DB::table('google_ads_search_term_daily')->where('search_term', 'dental implants')->first();
        $pmax = DB::table('google_ads_search_term_daily')->where('search_term', 'pmax term')->first();
        $this->assertNotNull($standard);
        $this->assertNotNull($pmax);
        $this->assertSame('search_term_view', json_decode((string) $standard->metadata, true)['source_view']);
        $this->assertSame('campaign_search_term_view', json_decode((string) $pmax->metadata, true)['source_view']);

        $keywords = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_KEYWORD, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $keywords->outcome, (string) $keywords->errorMessage);
        $kw = DB::table('google_ads_keyword_daily')->first();
        $this->assertSame('777', $kw->criterion_id);
        $this->assertTrue(json_decode((string) $kw->metadata, true)['keyword_neq_search_term']);
    }

    #[Test]
    public function landing_page_preserves_provider_url_without_website_join(): void
    {
        $this->fakeAdsHttp([
            'searchStream' => [
                'results' => [[
                    'segments' => ['date' => '2026-08-01'],
                    'landingPageView' => ['unexpandedFinalUrl' => 'https://example.com/lp?utm_source=google'],
                    'metrics' => ['impressions' => 1, 'clicks' => 1, 'costMicros' => '1000', 'conversions' => 0],
                ]],
            ],
        ]);

        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_LANDING_PAGE, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $row = DB::table('google_ads_landing_page_daily')->first();
        $this->assertSame('https://example.com/lp?utm_source=google', $row->landing_page);
        $this->assertFalse(json_decode((string) $row->metadata, true)['website_canonicalization']);
    }

    #[Test]
    public function late_correction_upserts_natural_key(): void
    {
        $responses = [
            ['results' => [[
                'segments' => ['date' => '2026-08-01'],
                'campaign' => ['id' => '1', 'name' => 'A', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'],
                'metrics' => ['impressions' => 1, 'clicks' => 1, 'costMicros' => '1000000', 'conversions' => 1],
            ]]],
            ['results' => [[
                'segments' => ['date' => '2026-08-01'],
                'campaign' => ['id' => '1', 'name' => 'A', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'],
                'metrics' => ['impressions' => 9, 'clicks' => 9, 'costMicros' => '9000000', 'conversions' => 3],
            ]]],
        ];
        $i = 0;

        Http::swap(new Factory);
        Http::fake(function ($request) use (&$responses, &$i) {
            if (str_contains($request->url(), 'searchStream') || str_contains($request->url(), 'googleAds:search')) {
                $payload = $responses[$i] ?? ['results' => []];
                $i++;

                return Http::response($payload, 200);
            }

            return Http::response(['results' => []], 200);
        });

        $first = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $first->outcome, (string) $first->errorMessage);
        $this->assertSame(1000000, (int) DB::table('google_ads_campaign_daily')->value('cost_micros'));

        $second = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $second->outcome, (string) $second->errorMessage);
        $this->assertSame(1, DB::table('google_ads_campaign_daily')->count());
        $this->assertSame(9000000, (int) DB::table('google_ads_campaign_daily')->value('cost_micros'));
    }

    #[Test]
    public function zero_row_search_is_completed_not_failure(): void
    {
        $this->fakeAdsHttp(['searchStream' => ['results' => []]]);
        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $this->assertSame(0, DB::table('google_ads_campaign_daily')->count());
    }

    #[Test]
    public function rate_limit_maps_to_shared_retry_and_executor_is_registered(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://googleads.googleapis.com/*' => Http::response(['error' => ['message' => 'Resource has been exhausted']], 403),
        ]);

        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Retry, $result->outcome);
        $this->assertSame(CollectionErrorCategory::Quota, $result->errorCategory);

        $this->assertContains(
            GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY,
            app(GoogleAdsDatasetExecutor::class)->supportedRequestFamilies(),
        );
        $this->assertInstanceOf(
            GoogleAdsDatasetExecutor::class,
            app(DatasetExecutorResolver::class)->resolve(
                CollectionDatasetRun::factory()->make([
                    'request_family_id' => GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY,
                ])
            ),
        );
    }

    #[Test]
    public function missing_developer_token_fails_without_provider_call(): void
    {
        config(['moxdop.google.developer_token' => null]);
        CoreIntegrationCredential::query()->where('integration_id', $this->integration->id)->delete();
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'ads-access-token',
                'refresh_token' => 'ads-refresh-token',
                'scope' => GoogleScopes::ADWORDS,
            ],
            'expires_at' => now()->addHour(),
        ]);

        Http::swap(new Factory);
        Http::fake();
        $result = $this->runFamily(GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('DEVELOPER_TOKEN_REQUIRED', $result->errorCode);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeAdsHttp(array $overrides = []): void
    {
        Http::swap(new Factory);

        $customer = $overrides['customer'] ?? [
            'results' => [[
                'customer' => [
                    'id' => '1112223333',
                    'descriptiveName' => 'Example Ads',
                    'currencyCode' => 'EUR',
                    'timeZone' => 'Europe/Berlin',
                    'manager' => false,
                    'testAccount' => false,
                ],
            ]],
        ];
        $campaigns = $overrides['campaigns'] ?? [
            'results' => [[
                'campaign' => [
                    'id' => '555',
                    'name' => 'Search Brand',
                    'status' => 'ENABLED',
                    'advertisingChannelType' => 'SEARCH',
                ],
                'campaignBudget' => [
                    'id' => '91',
                    'amountMicros' => '50000000',
                    'deliveryMethod' => 'STANDARD',
                    'explicitlyShared' => false,
                ],
            ]],
        ];
        $conversionMeta = $overrides['conversion_action'] ?? [
            'results' => [[
                'conversionAction' => [
                    'id' => '10',
                    'name' => 'Lead Form',
                    'status' => 'ENABLED',
                    'type' => 'WEBPAGE',
                    'category' => 'SUBMIT_LEAD_FORM',
                    'origin' => 'WEBSITE',
                    'primaryForGoal' => true,
                    'includeInConversionsMetric' => true,
                    'countingType' => 'ONE_PER_CLICK',
                ],
            ]],
        ];
        $conversionDaily = $overrides['conversion_daily'] ?? ['results' => []];
        $stream = $overrides['searchStream'] ?? ['results' => []];

        Http::fake(function ($request) use ($customer, $campaigns, $conversionMeta, $conversionDaily, $stream) {
            $url = $request->url();
            $query = is_array($request->data()) ? (string) ($request->data()['query'] ?? '') : '';

            if (str_contains($url, 'searchStream')) {
                if (is_callable($stream)) {
                    return $stream($request);
                }

                return Http::response($stream, 200);
            }

            if (str_contains($url, 'googleAds:search')) {
                if (str_contains($query, 'FROM customer') && str_contains($query, 'customer.id')) {
                    return Http::response($customer, 200);
                }
                if (str_contains($query, 'FROM campaign') && ! str_contains($query, 'segments.date')) {
                    return Http::response($campaigns, 200);
                }
                if (str_contains($query, 'FROM ad_group') && ! str_contains($query, 'ad_group_ad')) {
                    return Http::response(['results' => [[
                        'adGroup' => ['id' => '22', 'name' => 'AG', 'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD'],
                        'campaign' => ['id' => '555'],
                    ]]], 200);
                }
                if (str_contains($query, 'FROM ad_group_ad')) {
                    return Http::response(['results' => [[
                        'adGroupAd' => [
                            'status' => 'ENABLED',
                            'adStrength' => 'GOOD',
                            'ad' => ['id' => '33', 'type' => 'RESPONSIVE_SEARCH_AD', 'finalUrls' => ['https://example.com']],
                        ],
                        'adGroup' => ['id' => '22'],
                        'campaign' => ['id' => '555'],
                    ]]], 200);
                }
                if (str_contains($query, 'FROM keyword_view') && ! str_contains($query, 'segments.date')) {
                    return Http::response(['results' => [[
                        'adGroupCriterion' => [
                            'criterionId' => '777',
                            'status' => 'ENABLED',
                            'keyword' => ['text' => 'dental', 'matchType' => 'EXACT'],
                        ],
                        'adGroup' => ['id' => '22'],
                        'campaign' => ['id' => '555'],
                    ]]], 200);
                }
                if (str_contains($query, 'FROM asset')) {
                    return Http::response(['results' => [[
                        'asset' => ['id' => '44', 'name' => 'Sitelink', 'type' => 'SITELINK', 'source' => 'ADVERTISER'],
                    ]]], 200);
                }
                if (str_contains($query, 'FROM conversion_action')) {
                    return Http::response($conversionMeta, 200);
                }
                if (str_contains($query, 'segments.conversion_action')) {
                    return Http::response($conversionDaily, 200);
                }
                if (str_contains($query, 'FROM customer') && str_contains($query, 'segments.date')) {
                    return Http::response(['results' => []], 200);
                }

                return Http::response(['results' => []], 200);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });
    }

    /**
     * @param  array{start: string, end: string}|null  $dateRange
     */
    private function runFamily(string $family, ?array $dateRange = null): DatasetExecutionResult
    {
        [$executionContext, $datasetRun] = $this->makeContext($family, $dateRange);
        $executor = app(GoogleAdsDatasetExecutor::class);
        $result = $executor->execute($executionContext);
        $guard = 0;
        while ($result->outcome === DatasetExecutionOutcome::Continue && $guard < 80) {
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
     * @return array{0: DatasetExecutionContext, 1: CollectionDatasetRun}
     */
    private function makeContext(string $family, ?array $dateRange = null): array
    {
        $dateRange ??= ['start' => '2026-08-01', 'end' => '2026-08-02'];
        $definition = GoogleAdsRequestFamilyCatalog::definition($family);

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->brand->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => [
                'date_range' => $dateRange,
            ],
        ]);

        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'external_resource_id' => $this->resource->id,
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => $this->binding->id,
            'status' => CollectionRunStatus::Running,
        ]);

        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'GOOGLE_ADS',
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
                checkpoint: [],
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: 1,
            ),
            $datasetRun,
        ];
    }
}
