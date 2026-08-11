<?php

namespace Tests\Feature;

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
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use App\Support\Skills\SkillEligibilityEvaluator;
use App\Support\Skills\SkillRegistry;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoxDop\MetaAds\Ai\MetaAdsAiGuidanceContextBuilder;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Normalization\MetaActionNormalizer;
use MoxDop\MetaAds\Normalization\MetaResultResolver;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;
use Tests\TestCase;

class MetaAdsDataEngineCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreAssetBinding $binding;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'name' => 'Meta Data Engine Asset',
        ]);
        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_9001',
            'display_name' => 'Synthetic Ad Account',
            'metadata' => [
                'currency' => 'TRY',
                'timezone_name' => 'Europe/Istanbul',
                'business_name' => 'Synthetic Biz',
            ],
        ]);
        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        config(['moxdop.meta.access_token' => 'EAAG-synthetic-only']);
    }

    public function test_provider_id_joins_ignore_disjoint_list_order_and_names(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $query = $request->data();

            if (str_contains($url, '/insights') && str_contains($url, 'level=account')) {
                return Http::response(['data' => [[
                    'account_id' => '9001',
                    'spend' => '100',
                    'impressions' => '1000',
                    'actions' => [
                        ['action_type' => 'lead', 'value' => '5'],
                        ['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '2'],
                    ],
                    'date_start' => '2026-07-01',
                    'date_stop' => '2026-07-28',
                ]]], 200);
            }

            if (str_contains($url, '/insights') && str_contains($url, 'level=campaign')) {
                $this->assertSame('["spend_descending"]', $query['sort'] ?? null);
                $this->assertStringContainsString('GREATER_THAN', (string) ($query['filtering'] ?? ''));

                return Http::response(['data' => [[
                    'campaign_id' => 'camp-delivered',
                    'campaign_name' => 'Delivered Campaign',
                    'impressions' => '900',
                    'spend' => '80',
                    'reach' => '400',
                    'frequency' => '2.25',
                    'clicks' => '40',
                    'actions' => [['action_type' => 'lead', 'value' => '5']],
                    'date_start' => '2026-07-01',
                    'date_stop' => '2026-07-28',
                ]]], 200);
            }

            if (str_contains($url, '/campaigns')) {
                // Metadata for a different id would be the old bug; only return the Insights id.
                $filtering = (string) ($query['filtering'] ?? '');
                $this->assertStringContainsString('camp-delivered', $filtering);
                $this->assertStringNotContainsString('camp-unrelated', $filtering);

                return Http::response(['data' => [[
                    'id' => 'camp-delivered',
                    'name' => 'Delivered Campaign',
                    'status' => 'PAUSED',
                    'effective_status' => 'PAUSED',
                    'objective' => 'OUTCOME_LEADS',
                    'buying_type' => 'AUCTION',
                ]]], 200);
            }

            if (str_contains($url, '/insights') && str_contains($url, 'level=adset')) {
                return Http::response(['data' => [[
                    'adset_id' => 'adset-1',
                    'adset_name' => 'Adset Delivered',
                    'campaign_id' => 'camp-delivered',
                    'impressions' => '900',
                    'spend' => '80',
                    'actions' => [['action_type' => 'lead', 'value' => '5']],
                    'date_start' => '2026-07-01',
                    'date_stop' => '2026-07-28',
                ]]], 200);
            }

            if (str_contains($url, '/adsets')) {
                return Http::response(['data' => [[
                    'id' => 'adset-1',
                    'name' => 'Adset Delivered',
                    'campaign_id' => 'camp-delivered',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'optimization_goal' => 'LEAD_GENERATION',
                    'billing_event' => 'IMPRESSIONS',
                    'destination_type' => 'ON_AD',
                ]]], 200);
            }

            if (str_contains($url, '/insights') && str_contains($url, 'level=ad')) {
                return Http::response(['data' => [[
                    'ad_id' => 'ad-1',
                    'ad_name' => 'Ad Delivered',
                    'adset_id' => 'adset-1',
                    'campaign_id' => 'camp-delivered',
                    'impressions' => '900',
                    'spend' => '80',
                    'actions' => [['action_type' => 'lead', 'value' => '5']],
                    'date_start' => '2026-07-01',
                    'date_stop' => '2026-07-28',
                ]]], 200);
            }

            if (str_contains($url, '/ads')) {
                return Http::response(['data' => [[
                    'id' => 'ad-1',
                    'name' => 'Ad Delivered',
                    'adset_id' => 'adset-1',
                    'campaign_id' => 'camp-delivered',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'creative' => ['id' => 'cr-1', 'name' => 'Creative Delivered'],
                ]]], 200);
            }

            if (str_contains($url, '/cr-1')) {
                return Http::response([
                    'id' => 'cr-1',
                    'name' => 'Creative Delivered',
                    'title' => 'Headline',
                    'body' => 'Body text',
                    'call_to_action_type' => 'LEARN_MORE',
                    'link_url' => 'https://example.test/x',
                    'thumbnail_url' => 'https://example.test/t.jpg',
                    'object_type' => 'SHARE',
                    'status' => 'ACTIVE',
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $run = app(MetaAdsBoundCollector::class)->collect($this->binding->fresh(['digitalAsset', 'externalResource.integration']));
        $this->assertContains($run->status, ['completed', 'partial']);
        $this->assertIsArray($run->metadata['collection_stages'] ?? null);
        $this->assertSame('completed', data_get($run->metadata, 'collection_stages.campaign_insights.status'));

        $campaign = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE)->firstOrFail();
        $this->assertTrue((bool) data_get($campaign->payload, 'metrics_usable'));
        $this->assertSame('camp-delivered', data_get($campaign->payload, 'rows.0.campaign_id'));
        $this->assertSame('PAUSED', data_get($campaign->payload, 'rows.0.status'));
        $this->assertSame('OUTCOME_LEADS', data_get($campaign->payload, 'rows.0.objective'));
        $this->assertSame(80.0, (float) data_get($campaign->payload, 'rows.0.spend'));
        $this->assertTrue((bool) data_get($campaign->payload, 'rows.0.metadata_joined'));
        $this->assertSame('resolved', data_get($campaign->payload, 'rows.0.primary_result.status'));

        $adset = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_ADSET_PERFORMANCE)->firstOrFail();
        $this->assertSame('adset-1', data_get($adset->payload, 'rows.0.adset_id'));
        $this->assertSame('LEAD_GENERATION', data_get($adset->payload, 'rows.0.optimization_goal'));

        $ad = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE)->firstOrFail();
        $this->assertSame('ad-1', data_get($ad->payload, 'rows.0.ad_id'));
        $this->assertSame('cr-1', data_get($ad->payload, 'rows.0.creative_id'));

        $creative = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_CREATIVE_METADATA)->firstOrFail();
        $this->assertSame('cr-1', data_get($creative->payload, 'rows.0.creative_id'));
        $this->assertSame('Headline', data_get($creative->payload, 'rows.0.headline'));

        $account = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY)->firstOrFail();
        $mix = data_get($account->payload, 'result_mix');
        $this->assertSame('result_mix', $mix['mode'] ?? null);
        $this->assertFalse($mix['blind_action_sum'] ?? true);
        $this->assertGreaterThanOrEqual(2, count($mix['items'] ?? []));

        $workspace = app(MetaAdsWorkspaceData::class)->for($this->asset->fresh());
        $this->assertNotEmpty($workspace['result_mix']['items'] ?? []);
        $this->assertSame('Complete', $workspace['data_coverage']['campaigns'] ?? null);
    }

    public function test_missing_metrics_are_null_not_zero_when_provider_omits_fields(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/insights') && str_contains($url, 'level=account')) {
                return Http::response(['data' => [[
                    'spend' => '10',
                    'impressions' => '100',
                    'date_start' => '2026-07-01',
                    'date_stop' => '2026-07-28',
                ]]], 200);
            }
            if (str_contains($url, '/insights') && str_contains($url, 'level=campaign')) {
                return Http::response(['data' => [[
                    'campaign_id' => 'c1',
                    'campaign_name' => 'Sparse',
                    'impressions' => '50',
                    // spend intentionally omitted → must stay null, not 0
                    'date_start' => '2026-07-01',
                    'date_stop' => '2026-07-28',
                ]]], 200);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [[
                    'id' => 'c1',
                    'name' => 'Sparse',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'objective' => 'OUTCOME_TRAFFIC',
                ]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $run = app(MetaAdsBoundCollector::class)->collect($this->binding->fresh(['digitalAsset', 'externalResource.integration']));
        $campaign = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE)->firstOrFail();
        $this->assertNull(data_get($campaign->payload, 'rows.0.spend'));
        $this->assertNull(data_get($campaign->payload, 'rows.0.reach'));
        $this->assertNull(data_get($campaign->payload, 'rows.0.clicks'));
        $this->assertSame(50.0, (float) data_get($campaign->payload, 'rows.0.impressions'));

        $workspace = app(MetaAdsWorkspaceData::class)->for($this->asset->fresh());
        $this->assertNotEmpty($workspace['campaigns']);
        $this->assertNull($workspace['campaigns'][0]['spend']);
    }

    public function test_failed_campaign_insights_mark_partial_and_empty_rows_not_zeros(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/insights') && str_contains($url, 'level=account')) {
                return Http::response(['data' => [[
                    'spend' => '10',
                    'impressions' => '100',
                    'date_start' => '2026-07-01',
                    'date_stop' => '2026-07-28',
                ]]], 200);
            }
            if (str_contains($url, '/insights') && str_contains($url, 'level=campaign')) {
                return Http::response(['error' => ['message' => 'Rate limited', 'code' => 17]], 400);
            }

            return Http::response(['data' => []], 200);
        });

        $run = app(MetaAdsBoundCollector::class)->collect($this->binding->fresh(['digitalAsset', 'externalResource.integration']));
        $this->assertSame('partial', $run->status);
        $this->assertSame('failed', data_get($run->metadata, 'collection_stages.campaign_insights.status'));
        $this->assertSame('rate_limit', data_get($run->metadata, 'collection_stages.campaign_insights.error_category'));

        $campaign = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE)->firstOrFail();
        $this->assertFalse((bool) data_get($campaign->payload, 'response_ok'));
        $this->assertFalse((bool) data_get($campaign->payload, 'metrics_usable'));
        $this->assertSame([], data_get($campaign->payload, 'rows'));
    }

    public function test_result_mix_never_sums_distinct_action_types(): void
    {
        $actions = MetaActionNormalizer::normalize([
            ['action_type' => 'lead', 'value' => '10'],
            ['action_type' => 'purchase', 'value' => '3'],
            ['action_type' => 'landing_page_view', 'value' => '40'],
            ['action_type' => 'post_engagement', 'value' => '99'],
        ]);
        $mix = MetaResultResolver::resultMix($actions);
        $this->assertFalse($mix['blind_action_sum']);
        $counts = collect($mix['items'])->pluck('count')->all();
        $this->assertNotContains(10 + 3 + 40 + 99, $counts);
        $types = collect($mix['items'])->pluck('raw_action_type')->all();
        $this->assertContains('lead', $types);
        $this->assertContains('purchase', $types);
        $this->assertContains('landing_page_view', $types);
        $this->assertNotContains('post_engagement', $types);
        $this->assertSame(
            collect($mix['operator_items'])->pluck('human_label')->unique()->count(),
            collect($mix['operator_items'])->pluck('human_label')->count(),
        );
    }

    public function test_ai_coverage_gate_excludes_unusable_campaign_evidence_from_skills(): void
    {
        $run = Run::query()->create([
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => $this->binding->id,
            'module_id' => 'meta-ads',
            'status' => 'partial',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY,
            'title' => 'account',
            'payload' => [
                'response_ok' => true,
                'metrics_usable' => true,
                'current' => ['spend' => 10],
                'actions' => [],
                'result_mix' => ['mode' => 'result_mix', 'items' => [], 'blind_action_sum' => false],
            ],
            'observed_at' => now(),
        ]);
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'campaigns failed',
            'payload' => [
                'response_ok' => false,
                'metrics_usable' => false,
                'rows' => [],
            ],
            'observed_at' => now(),
        ]);
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE,
            'title' => 'ads failed',
            'payload' => [
                'response_ok' => false,
                'metrics_usable' => false,
                'rows' => [],
            ],
            'observed_at' => now(),
        ]);

        Finding::query()->create([
            'digital_asset_id' => $this->asset->id,
            'fingerprint' => 'meta-test-fp-1',
            'category' => 'performance',
            'severity' => 'medium',
            'title' => 'Synthetic finding',
            'summary' => 'For AI gate test',
            'confidence' => 0.8500,
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_run_id' => $run->id,
            'source_module' => 'meta-ads',
        ]);

        $built = app(MetaAdsAiGuidanceContextBuilder::class)->build($this->asset->fresh());
        $this->assertContains(MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY, $built['trustworthy_evidence_types']);
        $this->assertNotContains(MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE, $built['trustworthy_evidence_types']);
        $this->assertNotContains(MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE, $built['trustworthy_evidence_types']);

        $campaignSkill = app(SkillRegistry::class)->getForModule('meta-ads', 'campaign-performance-analysis');
        $adSkill = app(SkillRegistry::class)->getForModule('meta-ads', 'ad-creative-performance-analysis');
        $evaluator = app(SkillEligibilityEvaluator::class);

        $campaignEval = $evaluator->evaluate($campaignSkill, $built['trustworthy_evidence_types']);
        $adEval = $evaluator->evaluate($adSkill, $built['trustworthy_evidence_types']);
        $this->assertFalse($campaignEval['eligible']);
        $this->assertSame(SkillEligibilityEvaluator::MISSING_REQUIRED_EVIDENCE, $campaignEval['status']);
        $this->assertFalse($adEval['eligible']);
    }

    public function test_previous_comparison_integrity_requires_complete_previous_evidence(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/insights') && str_contains($url, 'level=account')) {
                static $n = 0;
                $n++;
                if ($n === 1) {
                    return Http::response(['data' => [[
                        'spend' => '100',
                        'impressions' => '1000',
                        'date_start' => '2026-07-14',
                        'date_stop' => '2026-08-10',
                    ]]], 200);
                }

                return Http::response(['error' => ['message' => 'fail', 'code' => 1]], 400);
            }

            return Http::response(['data' => []], 200);
        });

        $run = app(MetaAdsBoundCollector::class)->collect($this->binding->fresh(['digitalAsset', 'externalResource.integration']));
        $account = Evidence::query()->where('run_id', $run->id)->where('type', MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY)->firstOrFail();
        $this->assertSame([], data_get($account->payload, 'previous'));
        $this->assertSame([], data_get($account->payload, 'deltas'));
    }
}
