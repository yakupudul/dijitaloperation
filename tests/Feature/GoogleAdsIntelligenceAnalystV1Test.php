<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Findings\FindingLifecycleService;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;
use MoxDop\GoogleAds\Findings\GoogleAdsPerformanceBoundEvidenceEvaluator;
use MoxDop\GoogleAds\Findings\PerformanceFindingsCatalog;
use Tests\TestCase;

class GoogleAdsIntelligenceAnalystV1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        config([
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
            'ai.providers.openai.store' => false,
        ]);

        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-test-google-ads-ai',
        ], $this->admin);
    }

    public function test_search_term_findings_are_candidates_only_and_respect_sample_gates(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => GoogleAdsBoundCollector::EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE,
            'payload' => [
                'response_ok' => true,
                'rows' => [
                    [
                        'search_term' => 'cheap junk',
                        'campaign_id' => '1',
                        'ad_group_id' => '2',
                        'cost' => 40,
                        'clicks' => 25,
                        'conversions' => 0,
                        'targeting_status' => 'NONE',
                        'source_report' => 'search_term_view',
                        'advertising_channel_type' => 'SEARCH',
                    ],
                    [
                        'search_term' => 'low volume',
                        'campaign_id' => '1',
                        'ad_group_id' => '2',
                        'cost' => 1,
                        'clicks' => 2,
                        'conversions' => 0,
                        'targeting_status' => 'NONE',
                        'source_report' => 'search_term_view',
                    ],
                    [
                        'search_term' => 'winner query',
                        'campaign_id' => '1',
                        'ad_group_id' => '2',
                        'cost' => 10,
                        'clicks' => 8,
                        'conversions' => 2,
                        'targeting_status' => 'NONE',
                        'source_report' => 'search_term_view',
                    ],
                    [
                        'search_term' => 'pmax winner',
                        'campaign_id' => '9',
                        'ad_group_id' => null,
                        'cost' => 12,
                        'clicks' => 9,
                        'conversions' => 1,
                        'targeting_status' => null,
                        'source_report' => 'campaign_search_term_view',
                        'advertising_channel_type' => 'PERFORMANCE_MAX',
                    ],
                ],
            ],
        ]);

        $result = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$run->fresh('evidence')]);
        app(FindingLifecycleService::class)->apply($result);

        $titles = Finding::query()->where('digital_asset_id', $asset->id)->pluck('title')->all();
        $this->assertContains('Search term waste candidate', $titles);
        $this->assertContains('Search term opportunity candidate', $titles);

        $waste = Finding::query()->where('digital_asset_id', $asset->id)
            ->where('title', 'Search term waste candidate')->firstOrFail();
        $this->assertStringContainsString('Candidate for investigation', (string) $waste->summary);
        $this->assertStringContainsString('not an automatic negative keyword', strtolower((string) $waste->summary));
        $this->assertDoesNotMatchRegularExpression('/\bmust negate\b/i', (string) $waste->summary);

        $this->assertSame(0, Recommendation::query()->where('action', 'like', '%mutate%')->count());
        $this->assertSame(0, Task::query()->count());

        // Low-volume non-converting term should not create its own waste finding when below gates
        // (only one waste candidate expected from "cheap junk").
        $this->assertSame(
            1,
            Finding::query()->where('digital_asset_id', $asset->id)->where('title', 'Search term waste candidate')->count()
        );
    }

    public function test_failed_search_term_evidence_does_not_resolve_prior_findings(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $good = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $good->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => GoogleAdsBoundCollector::EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE,
            'payload' => [
                'response_ok' => true,
                'rows' => [[
                    'search_term' => 'waste',
                    'campaign_id' => '1',
                    'ad_group_id' => '1',
                    'cost' => 50,
                    'clicks' => 30,
                    'conversions' => 0,
                    'targeting_status' => 'NONE',
                    'source_report' => 'search_term_view',
                ]],
            ],
        ]);
        $first = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$good->fresh('evidence')]);
        app(FindingLifecycleService::class)->apply($first);
        $this->assertSame(1, Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'open')->count());

        $failed = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $failed->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => GoogleAdsBoundCollector::EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE,
            'payload' => [
                'response_ok' => false,
                'rows' => [],
            ],
        ]);
        // Also include account evidence so evaluation still succeeds for other rules, but not search rules.
        Evidence::factory()->create([
            'run_id' => $failed->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_account_summary',
            'payload' => [
                'response_ok' => true,
                'current' => ['cost' => 10, 'conversions' => 1, 'clicks' => 5, 'impressions' => 100],
                'previous' => ['cost' => 10, 'conversions' => 1, 'clicks' => 5, 'impressions' => 100],
                'deltas' => [],
            ],
        ]);

        $second = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [
            $good->fresh('evidence'),
            $failed->fresh('evidence'),
        ]);
        $this->assertNotContains(PerformanceFindingsCatalog::RULE_SEARCH_TERM_WASTE_CANDIDATE, $second->evaluatedRuleIds);
        app(FindingLifecycleService::class)->apply($second);

        $this->assertSame(
            'open',
            Finding::query()->where('digital_asset_id', $asset->id)->where('title', 'Search term waste candidate')->value('status')
        );
    }

    public function test_measurement_config_risk_does_not_claim_tracking_broken(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => GoogleAdsBoundCollector::EVIDENCE_TYPE_CONVERSION_ACTIONS,
            'payload' => [
                'response_ok' => true,
                'actions' => [],
                'action_count' => 0,
                'enabled_count' => 0,
                'usable_primary_or_included_count' => 0,
            ],
        ]);

        $result = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$run->fresh('evidence')]);
        app(FindingLifecycleService::class)->apply($result);

        $finding = Finding::query()->where('digital_asset_id', $asset->id)->firstOrFail();
        $this->assertSame('Measurement configuration risk', $finding->title);
        $this->assertStringNotContainsString('tracking is broken', strtolower((string) $finding->summary));
        $this->assertStringContainsString('configuration', strtolower((string) $finding->summary));
    }

    public function test_pmax_missing_fields_are_not_fabricated_in_evidence_normalization_contract(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => GoogleAdsBoundCollector::EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE,
            'payload' => [
                'response_ok' => true,
                'rows' => [[
                    'search_term' => 'pmax only',
                    'campaign_id' => '77',
                    'campaign_name' => 'PMax',
                    'advertising_channel_type' => 'PERFORMANCE_MAX',
                    'ad_group_id' => null,
                    'ad_group_name' => null,
                    'targeting_status' => null,
                    'cost' => 20,
                    'clicks' => 10,
                    'conversions' => 0,
                    'source_report' => 'campaign_search_term_view',
                ]],
                'limitations' => ['campaign_search_term_view for PERFORMANCE_MAX lacks ad_group and targeting status'],
            ],
        ]);

        $row = data_get(
            Evidence::query()->where('run_id', $run->id)->firstOrFail()->payload,
            'rows.0'
        );
        $this->assertNull($row['ad_group_id']);
        $this->assertNull($row['targeting_status']);
        $this->assertSame('campaign_search_term_view', $row['source_report']);
    }
}
