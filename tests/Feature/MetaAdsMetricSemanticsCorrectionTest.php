<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Normalization\MetaActionNormalizer;
use MoxDop\MetaAds\Normalization\MetaResultResolver;
use MoxDop\MetaAds\Support\MetaPercentage;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;
use Tests\TestCase;

class MetaAdsMetricSemanticsCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

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
            'name' => 'Meta Semantics Asset',
        ]);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_semantics',
            'display_name' => 'Semantics Account',
            'metadata' => ['currency' => 'TRY', 'business_name' => 'Biz'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    public function test_click_metric_families_are_not_ambiguously_paired(): void
    {
        $run = Run::query()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'meta-ads',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => ['partial_reasons' => [], 'collection_stages' => []],
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
                'requested_period' => ['start' => '2026-07-14', 'end' => '2026-08-10'],
                'comparison_period' => ['start' => '2026-06-16', 'end' => '2026-07-13'],
                'current' => [
                    'spend' => 40017.09,
                    'impressions' => 61989,
                    'clicks' => 2299,
                    'inline_link_clicks' => 1031,
                    'ctr' => 3.7087,
                    'inline_link_click_ctr' => 1.6632,
                    'cpc' => 17.4063,
                    'cost_per_inline_link_click' => 38.8139,
                    'cpm' => 645.5515,
                    'actions' => [],
                ],
                'previous' => ['spend' => 100],
                'deltas' => [],
                'actions' => [],
            ],
            'observed_at' => now(),
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'campaigns',
            'payload' => [
                'response_ok' => true,
                'metrics_usable' => true,
                'rows' => [[
                    'campaign_id' => '120247926313470075',
                    'campaign_name' => '09 | Diaspora TR | Form - Mox',
                    'status' => 'PAUSED',
                    'objective' => 'OUTCOME_LEADS',
                    'spend' => 40017.09,
                    'impressions' => 61989,
                    'reach' => 26797,
                    'frequency' => 2.3133,
                    'clicks' => 2299,
                    'inline_link_clicks' => 1031,
                    'ctr' => 3.7087,
                    'inline_link_click_ctr' => 1.6632,
                    'cpc' => 17.4063,
                    'cost_per_inline_link_click' => 38.8139,
                    'cpm' => 645.5515,
                    'primary_result' => [
                        'status' => 'resolved',
                        'raw_action_type' => 'lead',
                        'normalized_result_type' => 'lead',
                        'count' => 80,
                        'cost_per_result' => 500.2136,
                    ],
                    'actions' => [],
                ]],
            ],
            'observed_at' => now(),
        ]);

        foreach ([
            MetaAdsBoundCollector::EVIDENCE_ADSET_PERFORMANCE,
            MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE,
            MetaAdsBoundCollector::EVIDENCE_CREATIVE_METADATA,
        ] as $type) {
            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $this->asset->id,
                'source_module' => 'meta-ads',
                'type' => $type,
                'title' => $type,
                'payload' => ['response_ok' => true, 'metrics_usable' => true, 'rows' => [['id' => '1']], 'row_count' => 1],
                'observed_at' => now(),
            ]);
        }

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset->fresh());
        $kpiByKey = collect($data['kpis_full'])->keyBy('key');

        $this->assertSame('All Clicks CTR', $kpiByKey['ctr']['label']);
        $this->assertSame('Link CTR', $kpiByKey['inline_link_click_ctr']['label']);
        $this->assertSame('CPC (All)', $kpiByKey['cpc']['label']);
        $this->assertSame('Cost / Link Click', $kpiByKey['cost_per_inline_link_click']['label']);
        $this->assertSame('All Clicks', $kpiByKey['clicks']['label']);
        $this->assertSame('Link Clicks', $kpiByKey['inline_link_clicks']['label']);
        $this->assertSame('Link CTR', collect($data['kpis_secondary'])->firstWhere('key', 'inline_link_click_ctr')['label'] ?? null);

        $campaign = $data['campaigns'][0];
        $this->assertSame(2299.0, (float) $campaign['clicks']);
        $this->assertSame(1031.0, (float) $campaign['inline_link_clicks']);
        $this->assertEqualsWithDelta(3.7087, (float) $campaign['ctr'], 0.0001);
        $this->assertEqualsWithDelta(1.6632, (float) $campaign['inline_link_click_ctr'], 0.0001);
        $this->assertEqualsWithDelta(17.4063, (float) $campaign['cpc'], 0.0001);
        $this->assertEqualsWithDelta(38.8139, (float) $campaign['cost_per_inline_link_click'], 0.0001);

        // Provider math: Link CTR ≈ link_clicks / impressions * 100; All CTR stays distinct.
        $this->assertEqualsWithDelta(
            1.6632,
            ((float) $campaign['inline_link_clicks'] / (float) $campaign['impressions']) * 100,
            0.01,
        );
        $this->assertEqualsWithDelta(
            3.7087,
            ((float) $campaign['clicks'] / (float) $campaign['impressions']) * 100,
            0.01,
        );
        $this->assertNotSame(
            MetaPercentage::format($campaign['ctr']),
            MetaPercentage::format($campaign['inline_link_click_ctr']),
        );

        $html = view('meta-ads::workspace.performance', ['data' => $data])->render();
        $this->assertStringContainsString('All Clicks CTR', $html);
        $this->assertStringContainsString('Link CTR', $html);
        $this->assertStringContainsString('CPC (All)', $html);
        $this->assertStringContainsString('Cost / Link Click', $html);
        $this->assertStringNotContainsString('>CTR</th>', $html);
        $this->assertStringNotContainsString('>CPC</th>', $html);
    }

    public function test_mixed_result_signal_does_not_imply_collection_partial_when_run_completed(): void
    {
        $run = Run::query()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'meta-ads',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [
                'partial_reasons' => [],
                'collection_stages' => [
                    'account_insights' => ['status' => 'completed'],
                    'campaign_insights' => ['status' => 'completed'],
                    'campaign_metadata' => ['status' => 'completed'],
                    'adset_insights' => ['status' => 'completed'],
                    'ad_insights' => ['status' => 'completed'],
                    'creative_metadata' => ['status' => 'completed'],
                ],
            ],
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
                'current' => ['spend' => 10, 'attribution_setting' => 'multiple', 'actions' => []],
                'previous' => ['spend' => 5],
                'actions' => [],
            ],
            'observed_at' => now(),
        ]);
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'campaigns',
            'payload' => [
                'response_ok' => true,
                'metrics_usable' => true,
                'rows' => [
                    [
                        'campaign_id' => '1',
                        'campaign_name' => 'A',
                        'spend' => 5,
                        'attribution_setting' => 'multiple',
                        'primary_result' => ['status' => 'resolved', 'raw_action_type' => 'lead', 'count' => 1],
                    ],
                    [
                        'campaign_id' => '2',
                        'campaign_name' => 'B',
                        'spend' => 5,
                        'attribution_setting' => 'multiple',
                        'primary_result' => ['status' => 'unresolved', 'count' => null],
                    ],
                ],
            ],
            'observed_at' => now(),
        ]);
        foreach ([
            MetaAdsBoundCollector::EVIDENCE_ADSET_PERFORMANCE,
            MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE,
            MetaAdsBoundCollector::EVIDENCE_CREATIVE_METADATA,
        ] as $type) {
            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $this->asset->id,
                'source_module' => 'meta-ads',
                'type' => $type,
                'title' => $type,
                'payload' => ['response_ok' => true, 'rows' => [['id' => 'x']], 'row_count' => 1],
                'observed_at' => now(),
            ]);
        }

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset->fresh());
        $this->assertSame('Mixed', $data['data_coverage']['result_signal']);
        $this->assertSame('Complete', $data['data_coverage']['campaigns']);
        $this->assertSame('data_available', $data['workspace_state']);
        $this->assertSame([], $data['partial_reasons']);

        $html = view('meta-ads::workspace.overview', ['data' => $data])->render();
        $this->assertStringNotContainsString('Latest collection is partial', $html);
    }

    public function test_true_partial_run_exposes_exact_reasons(): void
    {
        $run = Run::query()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'meta-ads',
            'status' => 'partial',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [
                'partial_reasons' => ['ad_insights: Rate limited.'],
                'collection_stages' => [
                    'ad_insights' => ['status' => 'failed', 'error_category' => 'rate_limit'],
                ],
            ],
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
                'current' => ['spend' => 10, 'actions' => []],
                'actions' => [],
            ],
            'observed_at' => now(),
        ]);
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'campaigns',
            'payload' => ['response_ok' => true, 'rows' => [['campaign_id' => '1', 'spend' => 1, 'primary_result' => ['status' => 'resolved']]]],
            'observed_at' => now(),
        ]);

        $data = app(MetaAdsWorkspaceData::class)->for($this->asset->fresh());
        $this->assertSame('collection_partial', $data['workspace_state']);
        $this->assertContains('ad_insights: Rate limited.', $data['partial_reasons']);

        $html = view('meta-ads::workspace.overview', ['data' => $data])->render();
        $this->assertStringContainsString('ad_insights: Rate limited.', $html);
    }

    public function test_result_mix_uses_precise_labels_and_does_not_sum_aliases(): void
    {
        $actions = MetaActionNormalizer::normalize([
            ['action_type' => 'lead', 'value' => '119'],
            ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '119'],
            ['action_type' => 'onsite_conversion.total_messaging_connection', 'value' => '1390'],
            ['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '1257'],
            ['action_type' => 'onsite_conversion.messaging_first_reply', 'value' => '1219'],
            ['action_type' => 'landing_page_view', 'value' => '3'],
        ]);

        $mix = MetaResultResolver::resultMix($actions);
        $this->assertFalse($mix['blind_action_sum']);

        $operatorLabels = collect($mix['operator_items'])->pluck('human_label')->all();
        $this->assertSame($operatorLabels, array_unique($operatorLabels));
        $this->assertContains('Messaging connections', $operatorLabels);
        $this->assertContains('Messaging conversations started', $operatorLabels);
        $this->assertContains('Messaging first replies', $operatorLabels);
        $this->assertContains('Leads', $operatorLabels);
        $this->assertNotContains('Messaging Conversations', $operatorLabels);

        $lead = collect($mix['operator_items'])->firstWhere('raw_action_type', 'lead');
        $this->assertNotNull($lead);
        $this->assertSame(119.0, (float) $lead['count']);
        $this->assertContains('onsite_conversion.lead_grouped', $lead['aliases'] ?? []);
        $this->assertNull(collect($mix['operator_items'])->firstWhere('raw_action_type', 'onsite_conversion.lead_grouped'));

        $rawTypes = collect($mix['raw_items'])->pluck('raw_action_type')->all();
        $this->assertContains('lead', $rawTypes);
        $this->assertContains('onsite_conversion.lead_grouped', $rawTypes);
        $this->assertNotContains(119 + 119, collect($mix['operator_items'])->pluck('count')->all());
    }
}
