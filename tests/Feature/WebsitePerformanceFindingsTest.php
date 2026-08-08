<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Services\Findings\FindingLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\Website\Findings\PerformanceFindingsCatalog;
use MoxDop\Website\Findings\WebsitePerformanceBoundEvidenceEvaluator;
use Tests\TestCase;

class WebsitePerformanceFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gsc_and_ga4_decline_rules_open_findings_with_stable_fingerprints(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'type' => 'gsc_performance_summary',
            'payload' => [
                'response_ok' => true,
                'current' => ['clicks' => 50, 'impressions' => 300, 'ctr' => 0.04, 'position' => 12.0],
                'previous' => ['clicks' => 200, 'impressions' => 1000, 'ctr' => 0.10, 'position' => 8.0],
                'deltas' => [
                    'clicks' => ['absolute' => -150.0, 'percent' => -75.0],
                    'impressions' => ['absolute' => -700.0, 'percent' => -70.0],
                    'ctr' => ['absolute' => -0.06, 'percent' => -60.0],
                    'position' => ['absolute' => 4.0, 'percent' => 50.0],
                ],
            ],
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'type' => 'ga4_performance_summary',
            'payload' => [
                'response_ok' => true,
                'current' => ['totalUsers' => 20, 'sessions' => 25],
                'previous' => ['totalUsers' => 100, 'sessions' => 120],
                'deltas' => [
                    'totalUsers' => ['absolute' => -80.0, 'percent' => -80.0],
                    'sessions' => ['absolute' => -95.0, 'percent' => -79.17],
                ],
            ],
        ]);

        $result = app(WebsitePerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$run->fresh('evidence')]);
        $this->assertTrue($result->evaluationSuccessful);
        $this->assertEqualsCanonicalizing(
            array_merge(PerformanceFindingsCatalog::GSC_RULE_IDS, PerformanceFindingsCatalog::GA4_RULE_IDS),
            $result->evaluatedRuleIds,
        );
        $this->assertGreaterThanOrEqual(5, count($result->matches));

        app(FindingLifecycleService::class)->apply($result);

        $this->assertDatabaseHas('findings', [
            'digital_asset_id' => $asset->id,
            'fingerprint' => PerformanceFindingsCatalog::RULE_GSC_CLICKS_DECLINE,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('findings', [
            'digital_asset_id' => $asset->id,
            'fingerprint' => PerformanceFindingsCatalog::RULE_GA4_USERS_DECLINE,
            'status' => 'open',
        ]);
        $this->assertTrue(
            Finding::query()->where('digital_asset_id', $asset->id)->where('fingerprint', 'like', '%:%:%')->exists()
        );
    }

    public function test_failed_gsc_evidence_does_not_evaluate_or_resolve(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $openRun = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);
        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'fingerprint' => PerformanceFindingsCatalog::RULE_GSC_CLICKS_DECLINE,
            'status' => 'open',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        $failed = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'failed',
        ]);
        Evidence::factory()->create([
            'run_id' => $failed->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'type' => 'gsc_performance_summary',
            'payload' => ['response_ok' => false],
        ]);

        $result = app(WebsitePerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$failed->fresh('evidence')]);
        $this->assertFalse($result->evaluationSuccessful);
        $this->assertSame([], $result->evaluatedRuleIds);
        $this->assertSame([], $result->matches);

        $stats = app(FindingLifecycleService::class)->apply($result);
        $this->assertSame(0, $stats['resolved']);
        $this->assertSame('open', Finding::query()->where('fingerprint', PerformanceFindingsCatalog::RULE_GSC_CLICKS_DECLINE)->value('status'));
        $this->assertNotNull($openRun->id);
    }
}
