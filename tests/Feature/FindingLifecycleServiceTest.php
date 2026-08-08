<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\Findings\FindingLifecycleService;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_update_acknowledge_resolve_and_reopen_preserve_first_seen(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $run1 = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);
        $lifecycle = app(FindingLifecycleService::class);
        $ruleId = 'website:gsc:clicks-decline';
        $firstSeen = now()->subDays(2)->startOfSecond();

        $opened = $lifecycle->apply(new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'website',
            run: $run1,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [
                new RuleMatch(
                    ruleId: $ruleId,
                    fingerprint: $ruleId,
                    category: 'performance',
                    severity: 'high',
                    title: 'Search Console clicks declined',
                    summary: 'Clicks fell.',
                    confidence: 0.8,
                    recommendationTitle: 'Investigate clicks',
                    recommendationAction: 'Review GSC Evidence.',
                ),
            ],
            observedAt: $firstSeen,
        ));

        $this->assertSame(1, $opened['opened']);
        $finding = Finding::query()->where('fingerprint', $ruleId)->firstOrFail();
        $this->assertSame('open', $finding->status);
        $this->assertTrue($finding->first_seen_at->equalTo($firstSeen));
        $this->assertNull($finding->resolved_at);
        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());

        $finding->update(['status' => 'acknowledged']);
        $run2 = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);
        $seenAgainAt = now()->subDay()->startOfSecond();

        $updated = $lifecycle->apply(new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'website',
            run: $run2,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [
                new RuleMatch(
                    ruleId: $ruleId,
                    fingerprint: $ruleId,
                    category: 'performance',
                    severity: 'high',
                    title: 'Search Console clicks declined',
                    summary: 'Still down.',
                    confidence: 0.81,
                    recommendationTitle: 'Investigate clicks',
                    recommendationAction: 'Review GSC Evidence again.',
                ),
            ],
            observedAt: $seenAgainAt,
        ));

        $finding = $finding->fresh();
        $this->assertSame(1, $updated['updated']);
        $this->assertSame(0, $updated['opened']);
        $this->assertSame('acknowledged', $finding->status);
        $this->assertTrue($finding->first_seen_at->equalTo($firstSeen));
        $this->assertTrue($finding->last_seen_at->equalTo($seenAgainAt));
        $this->assertSame($run2->id, $finding->last_run_id);

        $run3 = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);
        $resolvedAt = now()->subHours(3)->startOfSecond();

        $resolved = $lifecycle->apply(new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'website',
            run: $run3,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: $resolvedAt,
        ));

        $finding = $finding->fresh();
        $this->assertSame(1, $resolved['resolved']);
        $this->assertSame('resolved', $finding->status);
        $this->assertTrue($finding->resolved_at->equalTo($resolvedAt));
        $this->assertTrue($finding->first_seen_at->equalTo($firstSeen));

        $run4 = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);
        $reopenedAt = now()->subHour()->startOfSecond();

        $reopened = $lifecycle->apply(new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'website',
            run: $run4,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [
                new RuleMatch(
                    ruleId: $ruleId,
                    fingerprint: $ruleId,
                    category: 'performance',
                    severity: 'high',
                    title: 'Search Console clicks declined',
                    summary: 'Back again.',
                    confidence: 0.83,
                    recommendationAction: 'Re-check GSC.',
                ),
            ],
            observedAt: $reopenedAt,
        ));

        $finding = $finding->fresh();
        $this->assertSame(1, $reopened['reopened']);
        $this->assertSame('open', $finding->status);
        $this->assertNull($finding->resolved_at);
        $this->assertTrue($finding->first_seen_at->equalTo($firstSeen));
        $this->assertSame($finding->id, Finding::query()->where('fingerprint', $ruleId)->value('id'));
    }

    public function test_failed_evaluation_never_auto_resolves_open_finding(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $runOk = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);
        $ruleId = 'website:ga4:users-decline';
        $lifecycle = app(FindingLifecycleService::class);

        $lifecycle->apply(new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'website',
            run: $runOk,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [
                new RuleMatch(
                    ruleId: $ruleId,
                    fingerprint: $ruleId,
                    category: 'performance',
                    severity: 'high',
                    title: 'GA4 users declined',
                    summary: 'Users fell.',
                    confidence: 0.8,
                ),
            ],
            observedAt: now()->subDay(),
        ));

        $finding = Finding::query()->where('fingerprint', $ruleId)->firstOrFail();
        $this->assertSame('open', $finding->status);

        $failedRun = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'failed',
        ]);
        Evidence::factory()->create([
            'run_id' => $failedRun->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'type' => 'ga4_performance_summary',
            'payload' => ['response_ok' => false],
        ]);

        $stats = $lifecycle->apply(new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'website',
            run: $failedRun,
            evaluationSuccessful: false,
            evaluatedRuleIds: [],
            matches: [],
            observedAt: now(),
        ));

        $this->assertSame(0, $stats['resolved']);
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertNull($finding->fresh()->resolved_at);
    }

    public function test_resolve_only_affects_fingerprints_owned_by_evaluated_rules(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        $lifecycle = app(FindingLifecycleService::class);

        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'fingerprint' => 'google-ads:conversions-decline',
            'status' => 'open',
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subDays(2),
        ]);
        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'fingerprint' => 'google-ads:campaign-spend-zero-conversions:999',
            'status' => 'open',
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subDays(2),
        ]);
        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'fingerprint' => 'google-ads:cpa-deterioration',
            'status' => 'open',
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subDays(2),
        ]);

        $stats = $lifecycle->apply(new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'google-ads',
            run: $run,
            evaluationSuccessful: true,
            evaluatedRuleIds: ['google-ads:conversions-decline', 'google-ads:campaign-spend-zero-conversions'],
            matches: [],
            observedAt: now(),
        ));

        $this->assertSame(2, $stats['resolved']);
        $this->assertSame('resolved', Finding::query()->where('fingerprint', 'google-ads:conversions-decline')->value('status'));
        $this->assertSame('resolved', Finding::query()->where('fingerprint', 'google-ads:campaign-spend-zero-conversions:999')->value('status'));
        $this->assertSame('open', Finding::query()->where('fingerprint', 'google-ads:cpa-deterioration')->value('status'));
    }
}
