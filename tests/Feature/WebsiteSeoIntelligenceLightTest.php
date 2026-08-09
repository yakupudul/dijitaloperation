<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoLabsMarketDirectory;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Integrations\PaidRequestFingerprint;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MoxDop\Website\SeoIntelligence\CrossSourceKeywordOpportunities;
use MoxDop\Website\SeoIntelligence\KeywordsForSiteCollector;
use MoxDop\Website\SeoIntelligence\KeywordsForSiteNormalizer;
use MoxDop\Website\SeoIntelligence\RankedKeywordsCollector;
use MoxDop\Website\SeoIntelligence\RankedKeywordsNormalizer;
use MoxDop\Website\SeoIntelligence\SeoIntelligenceConfig;
use MoxDop\Website\SeoIntelligence\SeoIntelligenceRefreshService;
use MoxDop\Website\SeoIntelligence\WebsiteDomainTarget;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;
use Tests\TestCase;

class WebsiteSeoIntelligenceLightTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $website;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.dataforseo.login' => null,
            'moxdop.dataforseo.password' => null,
            'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com',
            'moxdop.seo_intelligence.ranked_keywords.ttl_days' => 5,
            'moxdop.seo_intelligence.ranked_keywords.limit' => 100,
            'moxdop.seo_intelligence.keywords_for_site.ttl_days' => 7,
            'moxdop.seo_intelligence.keywords_for_site.limit' => 100,
            'moxdop.seo_intelligence.keywords_for_site.min_search_volume' => 10,
            'cache.default' => 'array',
        ]);

        Cache::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'name' => 'Moximu Website',
            'domain' => 'https://www.moximu.com/',
            'primary_url' => 'https://www.moximu.com/',
            'seo_market_location_code' => 2792,
            'seo_market_location_name' => 'Turkey',
            'seo_market_language_code' => 'tr',
            'seo_market_language_name' => 'Turkish',
        ]);

        $this->integration = CoreIntegration::factory()->dataforseo()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);
    }

    public function test_domain_target_strips_protocol_and_www(): void
    {
        $this->assertSame('moximu.com', WebsiteDomainTarget::fromAsset($this->website));
        $this->assertSame('moximu.com', WebsiteDomainTarget::normalize('https://www.moximu.com/path'));
    }

    public function test_first_ranked_keywords_request_makes_one_provider_call_and_stores_evidence(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::response(
                $this->rankedKeywordsFixture(cost: 0.0123),
                200,
            ),
        ]);

        $result = app(RankedKeywordsCollector::class)->collect(
            $this->website,
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertTrue($result['provider_called']);
        $this->assertSame(0.0123, $result['reported_cost_usd']);
        $this->assertSame('MISS', $result['cache_status']);
        $this->assertDatabaseHas('evidence', [
            'digital_asset_id' => $this->website->id,
            'type' => SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY,
        ]);
        $this->assertDatabaseHas('evidence', [
            'digital_asset_id' => $this->website->id,
            'type' => SeoIntelligenceConfig::EVIDENCE_RANKED_ROWS,
        ]);

        $summary = Evidence::query()
            ->where('type', SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY)
            ->first();
        $this->assertNotNull($summary?->request_fingerprint);
        $this->assertNotNull($summary?->fresh_until);
        $this->assertSame(0.0123, data_get($result['run']->metadata, 'reported_cost_usd'));
        $this->assertStringNotContainsString('dfs-secret-password', json_encode($result['run']->metadata));

        Http::assertSentCount(1);
    }

    public function test_identical_ranked_keywords_request_is_cache_hit_with_zero_provider_calls(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::response(
                $this->rankedKeywordsFixture(),
                200,
            ),
        ]);

        app(RankedKeywordsCollector::class)->collect($this->website, $this->integration->fresh(['providerCredential']));
        Http::assertSentCount(1);

        $second = app(RankedKeywordsCollector::class)->collect(
            $this->website,
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($second['provider_called']);
        $this->assertSame(0.0, $second['reported_cost_usd']);
        $this->assertSame('HIT_FRESH', $second['cache_status']);
        $this->assertSame(0, data_get($second['run']->metadata, 'provider_calls'));
        $this->assertTrue((bool) data_get($second['run']->metadata, 'provider_call_skipped'));

        Http::assertSentCount(1);
        $this->assertSame(
            1,
            Evidence::query()->where('type', SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY)->count(),
        );
    }

    public function test_market_and_language_changes_produce_new_fingerprints(): void
    {
        $turkey = app(RankedKeywordsCollector::class)->fingerprint($this->website, 'moximu.com');

        $this->website->forceFill([
            'seo_market_location_code' => 2276,
            'seo_market_language_code' => 'de',
        ])->save();

        $germany = app(RankedKeywordsCollector::class)->fingerprint($this->website->fresh(), 'moximu.com');
        $this->assertNotSame($turkey, $germany);

        $this->website->forceFill([
            'seo_market_location_code' => 2792,
            'seo_market_language_code' => 'en',
        ])->save();
        $english = app(RankedKeywordsCollector::class)->fingerprint($this->website->fresh(), 'moximu.com');
        $this->assertNotSame($turkey, $english);
    }

    public function test_stale_ranked_evidence_allows_exactly_one_new_provider_call(): void
    {
        $fingerprint = app(RankedKeywordsCollector::class)->fingerprint($this->website, 'moximu.com');
        $run = Run::factory()->create([
            'digital_asset_id' => $this->website->id,
            'status' => 'completed',
            'module_id' => 'website',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY,
            'request_fingerprint' => $fingerprint,
            'payload' => ['response_ok' => true, 'ok' => true],
            'fresh_until' => now()->subHour(),
            'observed_at' => now()->subDays(6),
        ]);

        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::response(
                $this->rankedKeywordsFixture(cost: 0.01),
                200,
            ),
        ]);

        $result = app(RankedKeywordsCollector::class)->collect(
            $this->website,
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertTrue($result['provider_called']);
        Http::assertSentCount(1);
        $this->assertSame(
            2,
            Evidence::query()->where('type', SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY)->count(),
        );
    }

    public function test_failed_provider_request_preserves_previous_valid_evidence(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::sequence()
                ->push($this->rankedKeywordsFixture(cost: 0.01), 200)
                ->push(['status_code' => 50000, 'status_message' => 'Internal Error.', 'cost' => 0, 'tasks' => []], 200),
        ]);

        app(RankedKeywordsCollector::class)->collect($this->website, $this->integration->fresh(['providerCredential']));

        $this->website->forceFill([
            'seo_market_language_code' => 'en',
            'seo_market_language_name' => 'English',
        ])->save();

        $before = Evidence::query()
            ->where('digital_asset_id', $this->website->id)
            ->where('type', SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY)
            ->count();

        try {
            app(RankedKeywordsCollector::class)->collect(
                $this->website->fresh(),
                $this->integration->fresh(['providerCredential']),
            );
            $this->fail('Expected provider failure');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(
            $before,
            Evidence::query()
                ->where('digital_asset_id', $this->website->id)
                ->where('type', SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY)
                ->where('payload->response_ok', true)
                ->count(),
        );
        $this->assertTrue(
            Run::query()
                ->where('digital_asset_id', $this->website->id)
                ->where('status', 'failed')
                ->where('metadata->capability', SeoIntelligenceConfig::CAPABILITY_RANKED)
                ->exists(),
        );
    }

    public function test_keywords_for_site_fresh_request_is_zero_provider_calls(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/keywords_for_site/live' => Http::response(
                $this->keywordsForSiteFixture(cost: 0.02),
                200,
            ),
        ]);

        app(KeywordsForSiteCollector::class)->collect(
            $this->website,
            $this->integration->fresh(['providerCredential']),
        );
        $second = app(KeywordsForSiteCollector::class)->collect(
            $this->website,
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($second['provider_called']);
        $this->assertSame(0.0, $second['reported_cost_usd']);
        Http::assertSentCount(1);
    }

    public function test_repeated_refresh_does_not_duplicate_paid_calls(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::response(
                $this->rankedKeywordsFixture(cost: 0.01),
                200,
            ),
            'https://api.dataforseo.com/v3/dataforseo_labs/google/keywords_for_site/live' => Http::response(
                $this->keywordsForSiteFixture(cost: 0.02),
                200,
            ),
        ]);

        $service = app(SeoIntelligenceRefreshService::class);
        $first = $service->refresh($this->website);
        $second = $service->refresh($this->website);

        $this->assertTrue($first['ok']);
        $this->assertSame(2, $first['provider_calls']);
        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['provider_calls']);
        $this->assertTrue($second['both_fresh']);

        Http::assertSentCount(2);
    }

    public function test_ranked_keywords_normalizer_bounds_and_organic_only(): void
    {
        $normalized = app(RankedKeywordsNormalizer::class)->normalize(
            $this->rankedKeywordsFixture()['tasks'][0]['result'][0],
            'moximu.com',
            2792,
            'tr',
            'Turkey',
            'Turkish',
            100,
            now()->toIso8601String(),
        );

        $this->assertTrue($normalized['summary']['response_ok']);
        $this->assertSame(3, $normalized['summary']['organic_distribution']['top_10']);
        $this->assertSame(42.5, $normalized['summary']['estimated_organic_traffic']);
        $this->assertCount(2, $normalized['rows']['rows']);
        $this->assertSame('seo agency', $normalized['rows']['rows'][0]['keyword']);
        $this->assertSame(8, $normalized['rows']['rows'][0]['rank_group']);
        $this->assertSame('/services', $normalized['rows']['rows'][0]['page_path']);
        $this->assertStringContainsString('not GA4', $normalized['summary']['metric_notes']['estimated_organic_traffic']);
    }

    public function test_keywords_for_site_normalizer_handles_optional_fields_and_volume_floor(): void
    {
        $normalized = app(KeywordsForSiteNormalizer::class)->normalize(
            $this->keywordsForSiteFixture()['tasks'][0]['result'][0],
            'moximu.com',
            2792,
            'tr',
            'Turkey',
            'Turkish',
            100,
            10,
            now()->toIso8601String(),
        );

        $keywords = collect($normalized['rows'])->pluck('keyword')->all();
        $this->assertContains('dijital pazarlama', $keywords);
        $this->assertNotContains('', $keywords);
        $this->assertNotContains('tiny keyword', $keywords);
        $this->assertSame(1200, $normalized['rows'][0]['search_volume']);
        $this->assertSame(35, $normalized['rows'][0]['keyword_difficulty']);
    }

    public function test_cross_source_exact_match_and_no_fuzzy_merge(): void
    {
        $this->seedSeoEvidence();

        $opps = app(CrossSourceKeywordOpportunities::class)->for($this->website);
        $byKeyword = collect($opps['opportunities'])->keyBy('keyword');

        $this->assertTrue($byKeyword->has('dijital pazarlama'));
        $this->assertSame(
            CrossSourceKeywordOpportunities::CATEGORY_NEW,
            $byKeyword['dijital pazarlama']['category'],
        );
        $this->assertStringContainsString(
            'not observed in the current GSC Evidence window',
            $byKeyword['dijital pazarlama']['why'],
        );

        $this->assertTrue($byKeyword->has('seo agency'));
        $this->assertSame(
            CrossSourceKeywordOpportunities::CATEGORY_EXISTING,
            $byKeyword['seo agency']['category'],
        );

        // Exact match only: "seo agencies" must not collapse into "seo agency".
        $this->assertTrue($byKeyword->has('seo agencies'));
        $this->assertSame(
            CrossSourceKeywordOpportunities::CATEGORY_NEW,
            $byKeyword['seo agencies']['category'],
        );
        $this->assertNotSame(
            $byKeyword['seo agency']['category'],
            $byKeyword['seo agencies']['category'],
        );
        $this->assertLessThanOrEqual(SeoIntelligenceConfig::opportunitiesMaxRows(), $opps['count']);
    }

    public function test_refresh_data_action_does_not_call_dataforseo(): void
    {
        Http::fake();

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertActionExists('refreshData')
            ->assertActionExists('refreshSeoIntelligence');

        Http::assertNothingSent();
    }

    public function test_workspace_presents_organic_visibility_and_activity_titles(): void
    {
        $this->seedSeoEvidence();

        $data = app(WebsiteWorkspaceData::class)->for($this->website);

        $this->assertSame('ready', $data['seo_intelligence']['state']);
        $this->assertNotEmpty($data['seo_intelligence']['kpis']);
        $this->assertNotEmpty($data['seo_intelligence']['ranked_keywords']);
        $this->assertTrue($data['seo_intelligence']['overview']['has_data']);

        $run = Run::factory()->create([
            'digital_asset_id' => $this->website->id,
            'module_id' => 'website',
            'status' => 'completed',
            'metadata' => [
                'capability' => SeoIntelligenceConfig::CAPABILITY_RANKED,
                'provider' => ProviderRegistry::DATAFORSEO,
                'cache_status' => 'HIT_FRESH',
                'provider_calls' => 0,
                'reported_cost_usd' => 0,
                'market' => [
                    'location_name' => 'Turkey',
                    'language_name' => 'Turkish',
                ],
            ],
        ]);

        $this->assertSame(
            'SEO keyword visibility refresh',
            app(WebsiteWorkspaceData::class)->runTitle($run),
        );
    }

    public function test_market_directory_uses_free_endpoint_and_cache(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/locations_and_languages' => Http::response([
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => 0,
                'tasks_count' => 1,
                'tasks_error' => 0,
                'tasks' => [[
                    'status_code' => 20000,
                    'cost' => 0,
                    'result' => [[
                        'location_code' => 2792,
                        'location_name' => 'Turkey',
                        'country_iso_code' => 'TR',
                        'location_type' => 'Country',
                        'available_languages' => [[
                            'available_sources' => ['google'],
                            'language_name' => 'Turkish',
                            'language_code' => 'tr',
                            'keywords' => 1000,
                            'serps' => 100,
                        ]],
                    ]],
                ]],
            ], 200),
        ]);

        $directory = app(DataForSeoLabsMarketDirectory::class);
        $first = $directory->googleMarkets($this->integration->fresh(['providerCredential']));
        $second = $directory->googleMarkets($this->integration->fresh(['providerCredential']));

        $this->assertTrue($first['ok']);
        $this->assertSame('Turkey', $first['locations'][0]['name']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        $this->assertSame(
            DataForSeoEndpointAllowlist::LABS_LOCATIONS_AND_LANGUAGES,
            DataForSeoEndpointAllowlist::assertAllowed('dataforseo_labs/locations_and_languages'),
        );
    }

    public function test_fingerprint_excludes_credentials(): void
    {
        $a = PaidRequestFingerprint::make('dataforseo', 'website_ranked_keywords', DataForSeoEndpointAllowlist::LABS_GOOGLE_RANKED_KEYWORDS_LIVE, [
            'target' => 'moximu.com',
            'password' => 'secret-a',
            'location_code' => 2792,
        ]);
        $b = PaidRequestFingerprint::make('dataforseo', 'website_ranked_keywords', DataForSeoEndpointAllowlist::LABS_GOOGLE_RANKED_KEYWORDS_LIVE, [
            'target' => 'moximu.com',
            'password' => 'secret-b',
            'location_code' => 2792,
        ]);
        $this->assertSame($a, $b);
    }

    private function seedSeoEvidence(): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->website->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY,
            'payload' => [
                'response_ok' => true,
                'total_count' => 120,
                'organic_distribution' => [
                    'top_10' => 12,
                    'top_20' => 30,
                    'count' => 120,
                ],
                'estimated_organic_traffic' => 450.2,
                'estimated_traffic_value' => 890.5,
                'retrieved_at' => now()->toIso8601String(),
            ],
            'fresh_until' => now()->addDays(5),
            'observed_at' => now(),
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => SeoIntelligenceConfig::EVIDENCE_RANKED_ROWS,
            'payload' => [
                'response_ok' => true,
                'rows' => [[
                    'keyword' => 'seo agency',
                    'rank_group' => 8,
                    'search_volume' => 720,
                    'url' => 'https://moximu.com/services',
                    'page_path' => '/services',
                    'cpc' => 1.2,
                    'keyword_difficulty' => 40,
                ]],
            ],
            'fresh_until' => now()->addDays(5),
            'observed_at' => now(),
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => SeoIntelligenceConfig::EVIDENCE_KEYWORD_OPPORTUNITIES,
            'payload' => [
                'response_ok' => true,
                'rows' => [
                    [
                        'keyword' => 'dijital pazarlama',
                        'search_volume' => 2400,
                        'cpc' => 0.8,
                        'keyword_difficulty' => 28,
                    ],
                    [
                        'keyword' => 'seo agency',
                        'search_volume' => 720,
                        'cpc' => 1.2,
                        'keyword_difficulty' => 40,
                    ],
                    [
                        'keyword' => 'seo agencies',
                        'search_volume' => 900,
                        'cpc' => 1.1,
                        'keyword_difficulty' => 42,
                    ],
                ],
            ],
            'fresh_until' => now()->addDays(7),
            'observed_at' => now(),
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => 'gsc_query_performance',
            'payload' => [
                'response_ok' => true,
                'rows' => [[
                    'query' => 'seo agency',
                    'clicks' => 12,
                    'impressions' => 400,
                    'ctr' => 0.03,
                    'position' => 7.5,
                ]],
            ],
            'observed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rankedKeywordsFixture(float $cost = 0.01): array
    {
        return [
            'version' => '0.1.20260101',
            'status_code' => 20000,
            'status_message' => 'Ok.',
            'cost' => $cost,
            'tasks_count' => 1,
            'tasks_error' => 0,
            'tasks' => [[
                'id' => '00000000-0000-0000-0000-000000000001',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => $cost,
                'result_count' => 1,
                'result' => [[
                    'se_type' => 'google',
                    'target' => 'moximu.com',
                    'location_code' => 2792,
                    'language_code' => 'tr',
                    'total_count' => 2,
                    'items_count' => 2,
                    'metrics' => [
                        'organic' => [
                            'pos_1' => 1,
                            'pos_2_3' => 1,
                            'pos_4_10' => 1,
                            'pos_11_20' => 2,
                            'count' => 5,
                            'etv' => 42.5,
                            'estimated_paid_traffic_cost' => 88.0,
                        ],
                    ],
                    'items' => [
                        [
                            'keyword_data' => [
                                'keyword' => 'seo agency',
                                'keyword_info' => [
                                    'last_updated_time' => '2026-08-01 00:00:00 +00:00',
                                    'search_volume' => 720,
                                    'cpc' => 1.25,
                                    'competition' => 0.4,
                                    'competition_level' => 'MEDIUM',
                                    'search_volume_trend' => ['monthly' => 5, 'quarterly' => 10, 'yearly' => 20],
                                ],
                                'keyword_properties' => [
                                    'keyword_difficulty' => 40,
                                ],
                            ],
                            'ranked_serp_element' => [
                                'serp_item' => [
                                    'type' => 'organic',
                                    'rank_group' => 8,
                                    'rank_absolute' => 10,
                                    'url' => 'https://moximu.com/services',
                                    'etv' => 12.2,
                                ],
                            ],
                        ],
                        [
                            'keyword_data' => [
                                'keyword' => 'paid only noise',
                                'keyword_info' => [
                                    'search_volume' => 100,
                                ],
                            ],
                            'ranked_serp_element' => [
                                'serp_item' => [
                                    'type' => 'paid',
                                    'rank_group' => 1,
                                    'url' => 'https://moximu.com/ads',
                                ],
                            ],
                        ],
                        [
                            'keyword_data' => [
                                'keyword' => 'web tasarim',
                                'keyword_info' => [
                                    'search_volume' => 540,
                                    'cpc' => 0.9,
                                ],
                                'keyword_properties' => [
                                    'keyword_difficulty' => 22,
                                ],
                            ],
                            'ranked_serp_element' => [
                                'serp_item' => [
                                    'type' => 'organic',
                                    'rank_group' => 3,
                                    'url' => 'https://moximu.com/',
                                    'etv' => 20.0,
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function keywordsForSiteFixture(float $cost = 0.02): array
    {
        return [
            'version' => '0.1.20260101',
            'status_code' => 20000,
            'status_message' => 'Ok.',
            'cost' => $cost,
            'tasks_count' => 1,
            'tasks_error' => 0,
            'tasks' => [[
                'id' => '00000000-0000-0000-0000-000000000002',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => $cost,
                'result_count' => 1,
                'result' => [[
                    'se_type' => 'google',
                    'target' => 'moximu.com',
                    'location_code' => 2792,
                    'language_code' => 'tr',
                    'total_count' => 3,
                    'items_count' => 3,
                    'items' => [
                        [
                            'keyword' => 'dijital pazarlama',
                            'keyword_info' => [
                                'search_volume' => 1200,
                                'cpc' => 0.75,
                                'competition' => 0.3,
                                'competition_level' => 'LOW',
                                'last_updated_time' => '2026-08-01 00:00:00 +00:00',
                                'search_volume_trend' => ['monthly' => 2, 'quarterly' => 4, 'yearly' => 8],
                                'monthly_searches' => [
                                    ['year' => 2026, 'month' => 7, 'search_volume' => 1100],
                                ],
                            ],
                            'keyword_properties' => [
                                'keyword_difficulty' => 35,
                            ],
                        ],
                        [
                            'keyword' => 'tiny keyword',
                            'keyword_info' => [
                                'search_volume' => 2,
                                'cpc' => 0.1,
                            ],
                        ],
                        [
                            'keyword' => '',
                            'keyword_info' => [
                                'search_volume' => 500,
                            ],
                        ],
                    ],
                ]],
            ]],
        ];
    }
}
