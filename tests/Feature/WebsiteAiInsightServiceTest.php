<?php

namespace Tests\Feature;

use App\Ai\Agents\WebsiteFindingInsightAgent;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\WebsiteAiInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class WebsiteAiInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_interpretation_creates_run_and_ai_insight_evidence(): void
    {
        [$asset, $finding, $evidence] = $this->seedWebsiteFindingWithEvidence();

        WebsiteFindingInsightAgent::fake([
            [
                'summary' => 'Sitemap is missing, which limits crawl discovery.',
                'finding_interpretations' => [
                    [
                        'finding_id' => $finding->id,
                        'likely_cause' => 'No readable sitemap.xml was returned for the host.',
                        'business_impact' => 'Search engines may discover pages more slowly.',
                        'suggested_priority' => 'medium',
                    ],
                ],
                'recommendation_drafts' => [
                    [
                        'finding_id' => $finding->id,
                        'title' => 'Publish a valid XML sitemap',
                        'action' => 'Publish https://example.com/sitemap.xml as a well-formed urlset.',
                        'rationale' => 'Evidence #'.$evidence->id.' shows sitemap availability failed.',
                        'priority' => 'medium',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $run = app(WebsiteAiInsightService::class)->interpret($asset);

        $this->assertSame('completed', $run->status);
        $this->assertSame(WebsiteAiInsightService::MODULE_ID, $run->module_id);
        $this->assertSame([$finding->id], $run->metadata['finding_ids']);
        $this->assertSame([$evidence->id], $run->metadata['evidence_ids']);

        $insight = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteAiInsightService::EVIDENCE_TYPE_AI_INSIGHT)
            ->first();

        $this->assertNotNull($insight);
        $this->assertTrue($insight->payload['ok']);
        $this->assertSame([$finding->id], $insight->payload['finding_ids']);
        $this->assertSame([$evidence->id], $insight->payload['evidence_ids']);
        $this->assertStringContainsString('Sitemap is missing', (string) $insight->payload['summary']);
        $this->assertSame('medium', $insight->payload['finding_interpretations'][0]['suggested_priority']);
        $this->assertSame('Publish a valid XML sitemap', $insight->payload['recommendation_drafts'][0]['title']);
        $this->assertArrayNotHasKey('assignee', $insight->payload['recommendation_drafts'][0]);
        $this->assertArrayNotHasKey('due_date', $insight->payload['recommendation_drafts'][0]);
        $this->assertSame(0, Recommendation::query()->count());

        WebsiteFindingInsightAgent::assertPrompted(function (AgentPrompt $prompt) use ($finding, $evidence): bool {
            return $prompt->contains('CONTEXT_JSON:')
                && $prompt->contains('"id": '.$finding->id)
                && $prompt->contains('"id": '.$evidence->id)
                && $prompt->contains('sitemap')
                && ! $prompt->contains(str_repeat('X', 500));
        });
    }

    public function test_interpret_accepts_explicit_finding_ids(): void
    {
        [$asset, $finding] = $this->seedWebsiteFindingWithEvidence();

        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'open',
            'title' => 'Ignored finding',
            'fingerprint' => 'ignored-finding',
        ]);

        WebsiteFindingInsightAgent::fake([
            [
                'summary' => 'Focused interpretation for one finding.',
                'finding_interpretations' => [
                    [
                        'finding_id' => $finding->id,
                        'likely_cause' => 'Sitemap endpoint unavailable.',
                        'business_impact' => 'Lower crawl efficiency.',
                        'suggested_priority' => 'high',
                    ],
                ],
                'recommendation_drafts' => [
                    [
                        'finding_id' => $finding->id,
                        'title' => 'Restore sitemap',
                        'action' => 'Serve a valid sitemap.xml.',
                        'rationale' => 'Based on finding '.$finding->id,
                        'priority' => 'high',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $run = app(WebsiteAiInsightService::class)->interpret($asset, [$finding->id]);

        $this->assertSame([$finding->id], $run->metadata['finding_ids']);
        $this->assertCount(1, $run->metadata['finding_ids']);
    }

    public function test_rejects_non_website_assets(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'google_ads',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('website Digital Asset');

        app(WebsiteAiInsightService::class)->interpret($asset);
    }

    public function test_rejects_empty_finding_set(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://example.com',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one Finding');

        app(WebsiteAiInsightService::class)->interpret($asset);
    }

    public function test_rejects_finding_ids_from_other_assets(): void
    {
        [$asset] = $this->seedWebsiteFindingWithEvidence();
        $otherFinding = Finding::factory()->create([
            'status' => 'open',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('belong to the given website Digital Asset');

        app(WebsiteAiInsightService::class)->interpret($asset, [$otherFinding->id]);
    }

    public function test_ai_failure_persists_failed_evidence_without_throwing(): void
    {
        [$asset] = $this->seedWebsiteFindingWithEvidence();

        WebsiteFindingInsightAgent::fake(function (): never {
            throw new RuntimeException('provider unavailable');
        })->preventStrayPrompts();

        $run = app(WebsiteAiInsightService::class)->interpret($asset);

        $this->assertSame('failed', $run->status);
        $this->assertSame('RuntimeException', $run->metadata['error_class']);

        $insight = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteAiInsightService::EVIDENCE_TYPE_AI_INSIGHT)
            ->first();

        $this->assertNotNull($insight);
        $this->assertFalse($insight->payload['ok']);
        $this->assertSame('ai_insight_failed', $insight->payload['status_or_error']);
        $this->assertSame('RuntimeException', $insight->payload['error_class']);
    }

    public function test_drops_ungrounded_finding_ids_from_model_output(): void
    {
        [$asset, $finding] = $this->seedWebsiteFindingWithEvidence();

        WebsiteFindingInsightAgent::fake([
            [
                'summary' => 'Keep only grounded finding references.',
                'finding_interpretations' => [
                    [
                        'finding_id' => $finding->id,
                        'likely_cause' => 'Valid grounded cause.',
                        'business_impact' => 'Valid impact.',
                        'suggested_priority' => 'medium',
                    ],
                    [
                        'finding_id' => 999999,
                        'likely_cause' => 'Invented finding.',
                        'business_impact' => 'Should be dropped.',
                        'suggested_priority' => 'critical',
                    ],
                ],
                'recommendation_drafts' => [
                    [
                        'finding_id' => 999999,
                        'title' => 'Invented draft',
                        'action' => 'Do not keep this.',
                        'rationale' => 'Ungrounded.',
                        'priority' => 'critical',
                    ],
                    [
                        'finding_id' => $finding->id,
                        'title' => 'Grounded draft',
                        'action' => 'Fix the sitemap.',
                        'rationale' => 'Matches finding '.$finding->id,
                        'priority' => 'medium',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $run = app(WebsiteAiInsightService::class)->interpret($asset);
        $insight = Evidence::query()->where('run_id', $run->id)->first();

        $this->assertTrue($insight->payload['ok']);
        $this->assertCount(1, $insight->payload['finding_interpretations']);
        $this->assertSame($finding->id, $insight->payload['finding_interpretations'][0]['finding_id']);
        $this->assertCount(1, $insight->payload['recommendation_drafts']);
        $this->assertSame('Grounded draft', $insight->payload['recommendation_drafts'][0]['title']);
    }

    /**
     * @return array{0: DigitalAsset, 1: Finding, 2: Evidence}
     */
    private function seedWebsiteFindingWithEvidence(): array
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://example.com',
        ]);

        $diagnosisRun = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);

        $evidence = Evidence::factory()->sitemap()->create([
            'run_id' => $diagnosisRun->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'payload' => [
                'host' => 'example.com',
                'tried_urls' => ['https://example.com/sitemap.xml'],
                'candidates_from_robots' => false,
                'sitemap_url' => null,
                'status_code' => 404,
                'present' => false,
                'parse_ok' => false,
                'root_element' => null,
                'url_count' => null,
                'body' => str_repeat('X', 1200),
                'body_truncated' => true,
                'last_outcome' => 'http_404',
                'error_class' => null,
                'reason_code' => 'not_found',
            ],
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'fingerprint' => 'sitemap-xml-availability|host=example.com',
            'category' => 'indexability',
            'severity' => 'medium',
            'title' => 'Sitemap missing or unreadable',
            'summary' => 'No readable sitemap.xml was found for example.com.',
            'confidence' => 0.7,
            'status' => 'open',
            'last_run_id' => $diagnosisRun->id,
        ]);

        return [$asset, $finding, $evidence];
    }
}
