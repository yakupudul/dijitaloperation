<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Support\Integrations\ComparisonPeriod;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Normalization\MetaResultResolver;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;
use MoxDop\MetaAds\Workspace\MetaWorkspaceFilters;
use Tests\TestCase;

class MetaAdsExpertWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $asset;

    private array $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Obezite Brand']);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'name' => 'Obezite ve Estetik',
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_744654160596455',
            'display_name' => 'Obezite ve Estetik',
            'metadata' => [
                'business_name' => 'Test BM',
                'currency' => 'TRY',
                'timezone_name' => 'Europe/Istanbul',
            ],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $this->period = ComparisonPeriod::forPreset(ComparisonPeriod::PRESET_LAST_30);
        MetaWorkspaceFilters::put((int) $this->asset->id, [
            'period_preset' => ComparisonPeriod::PRESET_LAST_30,
            'compare' => true,
            'delivery' => MetaWorkspaceFilters::DELIVERY_DELIVERED,
        ]);
    }

    public function test_period_mismatch_prepares_history_and_does_not_show_stale_as_current(): void
    {
        $this->seedAccountEvidence([
            'start' => '2020-01-01',
            'end' => '2020-01-28',
        ], spend: 100);

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);

        // No historical coverage and no Evidence for the selected period: we must not
        // surface stale numbers from the other-period Evidence, and we prepare history
        // in the background rather than forcing an "Analyze this period" gate.
        $this->assertFalse($data['period_matched']);
        $this->assertFalse($data['needs_analyze']);
        $this->assertSame([], $data['kpis']);
        $this->assertSame('preparing', $data['history']['state']);
        $this->assertStringContainsString('Preparing missing history', (string) $data['history']['message']);
        $this->assertStringNotContainsString('active Meta binding', strtolower(json_encode($data['connection_health'])));
    }

    public function test_matched_period_loads_priority_kpis_result_mix_and_data_health_badge(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous']);

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);

        $this->assertTrue($data['period_matched']);
        $this->assertFalse($data['needs_analyze']);
        $this->assertNotEmpty($data['kpis']);
        $this->assertSame('spend', $data['kpis'][0]['key']);
        $this->assertSame('result_mix', $data['result_mix']['mode']);
        $this->assertFalse($data['result_mix']['blind_action_sum']);
        $this->assertStringContainsString('Data Health', $data['data_health']['label']);
        $this->assertTrue($data['trend']['available']);
        $this->assertCount(3, $data['delivery_flow']['stages']);
        $this->assertSame('Unavailable', $data['delivery_flow']['stages'][2]['available'] ? 'Available' : 'Unavailable');
    }

    public function test_delivered_in_period_filter_hides_zero_delivery_campaigns_by_default(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous']);

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $names = collect($data['campaigns'])->pluck('name')->all();

        $this->assertContains('Delivered Campaign', $names);
        $this->assertNotContains('Zero Campaign', $names);

        MetaWorkspaceFilters::put((int) $this->asset->id, ['delivery' => MetaWorkspaceFilters::DELIVERY_ALL]);
        $all = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertContains('Zero Campaign', collect($all['campaigns'])->pluck('name')->all());
    }

    public function test_active_paused_objective_and_search_filters(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous']);

        MetaWorkspaceFilters::put((int) $this->asset->id, [
            'delivery' => MetaWorkspaceFilters::DELIVERY_ACTIVE,
        ]);
        $active = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertTrue(collect($active['campaigns'])->every(
            fn (array $row): bool => strtoupper((string) ($row['effective_status'] ?? '')) === 'ACTIVE'
        ));

        MetaWorkspaceFilters::put((int) $this->asset->id, [
            'delivery' => MetaWorkspaceFilters::DELIVERY_PAUSED,
        ]);
        $paused = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertContains('Paused Delivered', collect($paused['campaigns'])->pluck('name')->all());

        MetaWorkspaceFilters::put((int) $this->asset->id, [
            'delivery' => MetaWorkspaceFilters::DELIVERY_DELIVERED,
            'objective' => 'OUTCOME_LEADS',
            'search' => 'Delivered',
        ]);
        $filtered = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertSame(['Delivered Campaign'], collect($filtered['campaigns'])->pluck('name')->all());
    }

    public function test_analyze_this_period_queues_async_without_blocking_and_keeps_prior_dashboard(): void
    {
        Queue::fake();
        $this->seedAccountEvidence(['start' => '2020-01-01', 'end' => '2020-01-28'], spend: 55);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Campaigns')
            ->assertSee('Creatives')
            ->assertSee('Insights')
            ->assertSee('Connection')
            ->assertDontSee('active Meta binding')
            ->call('analyzeMetaSelectedPeriod')
            ->assertNotified();

        $run = Run::query()
            ->where('digital_asset_id', $this->asset->id)
            ->where('metadata->async', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('queued', $run->status);
        $this->assertSame(ComparisonPeriod::PRESET_LAST_30, data_get($run->metadata, 'period_preset'));
        $this->assertSame('Analyze this period', data_get($run->metadata, 'human_title'));

        MetaWorkspaceFilters::put((int) $this->asset->id, [
            'period_preset' => ComparisonPeriod::PRESET_LAST_30,
        ]);
        $this->seedFullEvidence($this->period['current'], $this->period['previous']);

        // Simulate in-flight collection for a newly selected period while matched data exists.
        Run::query()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'async-bound-collect',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'async' => true,
                'operation_type' => 'bound_collect',
                'phase_label' => 'Collecting',
            ],
        ]);

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertTrue($data['period_matched']);
        $this->assertNotEmpty($data['kpis']);
        $this->assertNotNull($data['async_collection']);
    }

    public function test_attention_prioritizes_high_findings_and_empty_state_is_calm(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous']);

        $empty = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertSame([], $empty['attention']['items']);
        $this->assertStringContainsString('No high-confidence', $empty['attention']['empty_label']);

        Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'fingerprint' => 'meta-high-1',
            'severity' => 'high',
            'status' => 'open',
            'title' => 'Delivered Campaign efficiency deteriorated',
            'summary' => 'Cost/result rose while spend stayed material.',
        ]);
        Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'fingerprint' => 'meta-low-1',
            'severity' => 'low',
            'status' => 'open',
            'title' => 'Low noise',
            'summary' => 'Should not dominate attention.',
        ]);

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $this->assertCount(1, $data['attention']['items']);
        $this->assertSame('high', $data['attention']['items'][0]['severity']);
    }

    public function test_click_metric_labels_and_creative_provider_association_preserved(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous']);
        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);

        $labels = collect($data['kpis_secondary'])->pluck('label')->all();
        $this->assertContains('Link CTR', $labels);

        $campaign = $data['campaigns'][0];
        $this->assertArrayHasKey('ctr', $campaign);
        $this->assertArrayHasKey('inline_link_click_ctr', $campaign);

        $creative = collect($data['creatives'])->firstWhere('creative_id', 'cr_1');
        $this->assertNotNull($creative);
        $this->assertSame('https://example.test/thumb.jpg', $creative['thumbnail_url']);
        $this->assertSame(40.0, (float) $creative['spend']);
    }

    public function test_landing_page_views_missing_are_unavailable_not_zero(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous']);
        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);
        $lpv = collect($data['delivery_flow']['stages'])->firstWhere('key', 'landing_page_view');
        $this->assertFalse($lpv['available']);
        $this->assertNull($lpv['value']);
    }

    public function test_queue_bound_collect_service_accepts_period_metadata(): void
    {
        Queue::fake();
        $result = app(AsyncOperationService::class)->queueBoundCollect($this->asset, $this->admin, [
            'period_preset' => ComparisonPeriod::PRESET_LAST_7,
            'compare' => false,
            'human_title' => 'Analyze this period',
        ]);

        $this->assertTrue($result['queued']);
        $this->assertSame(ComparisonPeriod::PRESET_LAST_7, data_get($result['run']->metadata, 'period_preset'));
        $this->assertFalse((bool) data_get($result['run']->metadata, 'compare'));
    }

    /**
     * @param  array{start: string, end: string}  $period
     */
    private function seedAccountEvidence(array $period, float $spend): void
    {
        $run = Run::query()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => MetaAdsBoundCollector::MODULE_ID,
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addMinutes(2),
            'metadata' => [],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY,
            'title' => 'Account',
            'observed_at' => now()->subHour(),
            'payload' => [
                'requested_period' => $period,
                'comparison_period' => ['start' => '2019-12-01', 'end' => '2019-12-28'],
                'account_name' => 'Obezite ve Estetik',
                'account_id' => 'act_744654160596455',
                'currency' => 'TRY',
                'business_name' => 'Test BM',
                'response_ok' => true,
                'metrics_usable' => true,
                'current' => [
                    'spend' => $spend,
                    'impressions' => 1000,
                    'reach' => 800,
                    'frequency' => 1.25,
                    'clicks' => 50,
                    'inline_link_clicks' => 40,
                    'ctr' => 5.0,
                    'inline_link_click_ctr' => 4.0,
                    'cpc' => 2.0,
                    'cpm' => 100.0,
                    'actions' => [
                        ['raw_action_type' => 'lead', 'normalized_result_type' => 'lead', 'count' => 3],
                        ['raw_action_type' => 'link_click', 'normalized_result_type' => 'link_click', 'count' => 40],
                    ],
                ],
                'previous' => [
                    'spend' => $spend * 0.8,
                    'impressions' => 900,
                ],
                'deltas' => [
                    'spend' => ['percent' => 25.0],
                ],
                'actions' => [
                    ['raw_action_type' => 'lead', 'normalized_result_type' => 'lead', 'count' => 3],
                    ['raw_action_type' => 'link_click', 'normalized_result_type' => 'link_click', 'count' => 40],
                ],
            ],
        ]);
    }

    /**
     * @param  array{start: string, end: string}  $current
     * @param  array{start: string, end: string}  $previous
     */
    private function seedFullEvidence(array $current, array $previous, bool $withDailyTrend = true): void
    {
        $run = Run::query()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => MetaAdsBoundCollector::MODULE_ID,
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addMinutes(3),
            'metadata' => [
                'collection_stages' => ['account_insights' => ['status' => 'completed']],
            ],
        ]);

        $base = [
            'requested_period' => $current,
            'comparison_period' => $previous,
            'response_ok' => true,
            'metrics_usable' => true,
        ];

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY,
            'title' => 'Account',
            'observed_at' => now()->subHour(),
            'payload' => [
                ...$base,
                'account_name' => 'Obezite ve Estetik',
                'account_id' => 'act_744654160596455',
                'currency' => 'TRY',
                'business_name' => 'Test BM',
                'current' => [
                    'spend' => 120.5,
                    'impressions' => 10000,
                    'reach' => 7000,
                    'frequency' => 1.4,
                    'clicks' => 300,
                    'inline_link_clicks' => 220,
                    'ctr' => 3.0,
                    'inline_link_click_ctr' => 2.2,
                    'cpc' => 0.4,
                    'cpm' => 12.0,
                    'actions' => [
                        ['raw_action_type' => 'lead', 'normalized_result_type' => 'lead', 'count' => 8],
                    ],
                ],
                'previous' => [
                    'spend' => 100.0,
                    'impressions' => 9000,
                ],
                'deltas' => [
                    'spend' => ['percent' => 20.5],
                    'inline_link_click_ctr' => ['percent' => -5.0],
                ],
                'actions' => [
                    ['raw_action_type' => 'lead', 'normalized_result_type' => 'lead', 'count' => 8],
                    ['raw_action_type' => 'purchase', 'normalized_result_type' => 'purchase', 'count' => 2],
                ],
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'Campaigns',
            'observed_at' => now()->subHour(),
            'payload' => [
                ...$base,
                'rows' => [
                    [
                        'campaign_id' => 'cmp_1',
                        'campaign_name' => 'Delivered Campaign',
                        'status' => 'ACTIVE',
                        'effective_status' => 'ACTIVE',
                        'objective' => 'OUTCOME_LEADS',
                        'spend' => 80,
                        'impressions' => 5000,
                        'reach' => 4000,
                        'frequency' => 1.2,
                        'clicks' => 100,
                        'inline_link_clicks' => 80,
                        'ctr' => 2.0,
                        'inline_link_click_ctr' => 1.6,
                        'cpc' => 0.8,
                        'cpm' => 16,
                        'primary_result' => [
                            'status' => 'resolved',
                            'raw_action_type' => 'lead',
                            'normalized_result_type' => 'lead',
                            'count' => 5,
                            'cost_per_result' => 16,
                        ],
                    ],
                    [
                        'campaign_id' => 'cmp_paused',
                        'campaign_name' => 'Paused Delivered',
                        'status' => 'PAUSED',
                        'effective_status' => 'PAUSED',
                        'objective' => 'OUTCOME_TRAFFIC',
                        'spend' => 20,
                        'impressions' => 1000,
                        'reach' => 900,
                        'primary_result' => ['status' => 'resolved', 'raw_action_type' => 'link_click', 'normalized_result_type' => 'link_click', 'count' => 40, 'cost_per_result' => 0.5],
                    ],
                    [
                        'campaign_id' => 'cmp_zero',
                        'campaign_name' => 'Zero Campaign',
                        'status' => 'ACTIVE',
                        'effective_status' => 'ACTIVE',
                        'objective' => 'OUTCOME_AWARENESS',
                        'spend' => 0,
                        'impressions' => 0,
                        'primary_result' => ['status' => 'none'],
                    ],
                ],
                'row_count' => 3,
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_ADSET_PERFORMANCE,
            'title' => 'Ad sets',
            'observed_at' => now()->subHour(),
            'payload' => [
                ...$base,
                'rows' => [[
                    'adset_id' => 'as_1',
                    'adset_name' => 'Ad Set A',
                    'campaign_id' => 'cmp_1',
                    'campaign_name' => 'Delivered Campaign',
                    'status' => 'ACTIVE',
                    'spend' => 50,
                    'impressions' => 3000,
                    'inline_link_click_ctr' => 1.5,
                    'primary_result' => ['status' => 'resolved', 'raw_action_type' => 'lead', 'normalized_result_type' => 'lead', 'count' => 3, 'cost_per_result' => 16.6],
                ]],
                'row_count' => 1,
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE,
            'title' => 'Ads',
            'observed_at' => now()->subHour(),
            'payload' => [
                ...$base,
                'rows' => [[
                    'ad_id' => 'ad_1',
                    'ad_name' => 'Ad One',
                    'adset_id' => 'as_1',
                    'campaign_id' => 'cmp_1',
                    'status' => 'ACTIVE',
                    'spend' => 40,
                    'frequency' => 1.8,
                    'ctr' => 2.1,
                    'inline_link_click_ctr' => 1.7,
                    'creative_id' => 'cr_1',
                    'creative_name' => 'Creative One',
                    'primary_result' => ['status' => 'resolved', 'raw_action_type' => 'lead', 'normalized_result_type' => 'lead', 'count' => 2, 'cost_per_result' => 20],
                ]],
                'row_count' => 1,
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_CREATIVE_METADATA,
            'title' => 'Creatives',
            'observed_at' => now()->subHour(),
            'payload' => [
                ...$base,
                'rows' => [[
                    'creative_id' => 'cr_1',
                    'creative_name' => 'Creative One',
                    'headline' => 'Book a consult',
                    'body' => 'Trusted care for lasting results.',
                    'call_to_action_type' => 'LEARN_MORE',
                    'link_url' => 'https://example.test/landing',
                    'thumbnail_url' => 'https://example.test/thumb.jpg',
                    'object_type' => 'SHARE',
                    'status' => 'ACTIVE',
                    'untrusted_text' => true,
                ]],
                'row_count' => 1,
                'metadata_usable' => true,
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_ACCOUNT_DAILY_TREND,
            'title' => 'Daily',
            'observed_at' => now()->subHour(),
            'payload' => $withDailyTrend ? [
                ...$base,
                'granularity' => 'day',
                'time_increment' => 1,
                'response_ok' => true,
                'points' => [
                    ['date' => $current['start'], 'spend' => 10, 'inline_link_click_ctr' => 1.1, 'cpm' => 11, 'frequency' => 1.1, 'impressions' => 100, 'inline_link_clicks' => 5],
                    ['date' => $current['end'], 'spend' => 20, 'inline_link_click_ctr' => 1.4, 'cpm' => 12, 'frequency' => 1.3, 'impressions' => 200, 'inline_link_clicks' => 8],
                ],
                'point_count' => 2,
            ] : [
                ...$base,
                'granularity' => 'day',
                'time_increment' => 1,
                'response_ok' => true,
                'points' => [],
                'point_count' => 0,
            ],
        ]);
    }

    public function test_campaign_05_resolves_messaging_from_conversations_optimization(): void
    {
        $actions = [
            ['raw_action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'normalized_result_type' => 'messaging', 'count' => 1252.0, 'value' => null, 'source' => 'actions'],
            ['raw_action_type' => 'link_click', 'normalized_result_type' => 'engagement', 'count' => 6083.0, 'value' => null, 'source' => 'actions'],
        ];

        $resolved = MetaResultResolver::resolve(
            $actions,
            'OUTCOME_LEADS',
            'CONVERSATIONS',
            5000.0,
            null,
            'WHATSAPP',
            '1d_view_7d_click',
        );

        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('onsite_conversion.messaging_conversation_started_7d', $resolved['raw_action_type']);
        $this->assertSame('messaging', $resolved['normalized_result_type']);
        $this->assertSame(1252.0, $resolved['count']);

        $campaign = [
            'campaign_id' => 'c05',
            'objective' => 'OUTCOME_LEADS',
            'spend' => 5000.0,
            'actions' => $actions,
            'attribution_setting' => '1d_view_7d_click',
        ];
        $adsets = [[
            'campaign_id' => 'c05',
            'optimization_goal' => 'CONVERSATIONS',
            'destination_type' => 'WHATSAPP',
            'spend' => 3000.0,
            'impressions' => 1000,
            'primary_result' => $resolved,
            'primary_result_status' => 'resolved',
            'actions' => $actions,
        ]];

        $inherited = MetaResultResolver::applyCampaignAdSetConsensus($campaign, $adsets);
        $this->assertSame('resolved', $inherited['primary_result_status']);
        $this->assertSame(
            'Messaging conversations started',
            MetaResultResolver::humanLabel($resolved['raw_action_type'], $resolved['normalized_result_type']),
        );
    }

    public function test_campaign_07_resolves_profile_visits_not_generic_link_click_label(): void
    {
        $actions = [
            ['raw_action_type' => 'link_click', 'normalized_result_type' => 'engagement', 'count' => 3448.0, 'value' => null, 'source' => 'actions'],
        ];

        $resolved = MetaResultResolver::resolve(
            $actions,
            'OUTCOME_TRAFFIC',
            'PROFILE_VISIT',
            1500.0,
            null,
            'INSTAGRAM_PROFILE',
            '1d_click',
        );

        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('link_click', $resolved['raw_action_type']);
        $this->assertSame('profile_visit', $resolved['normalized_result_type']);

        $label = MetaResultResolver::humanLabel(
            $resolved['raw_action_type'],
            $resolved['normalized_result_type'],
        );
        $this->assertSame('Profile visits', $label);
    }

    public function test_data_health_not_complete_when_trend_missing(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous'], withDailyTrend: false);
        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);

        $this->assertSame('Not analyzed', $data['data_health']['detail']['trend'] ?? null);
        $this->assertStringContainsString('Partial', $data['data_health']['label']);
        $this->assertFalse($data['trend']['available']);
    }

    public function test_data_health_complete_when_trend_present(): void
    {
        $this->seedFullEvidence($this->period['current'], $this->period['previous'], withDailyTrend: true);
        $data = app(MetaAdsWorkspaceData::class)->for($this->asset);

        $this->assertSame('Complete', $data['data_health']['detail']['trend'] ?? null);
        $this->assertTrue($data['trend']['available']);
    }
}
