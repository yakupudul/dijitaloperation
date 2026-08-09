<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\User;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Services\WebsiteAiInsightService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use MoxDop\Website\Ai\Agents\WebsiteRecommendationAgent;
use MoxDop\Website\Ai\WebsiteAiRecommendationConfig;
use RuntimeException;
use Tests\TestCase;

class WebsiteAiInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        config([
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
            'ai.providers.openai.store' => false,
            'moxdop.openai.recommendation_model' => 'gpt-5-mini',
        ]);

        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-test-compat',
        ], $admin);
    }

    public function test_successful_interpretation_creates_run_and_ai_insight_evidence(): void
    {
        [$asset, $finding, $evidence] = $this->seedWebsiteFindingWithEvidence();

        WebsiteRecommendationAgent::fake([
            $this->structured($finding->id, $evidence->id),
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

        WebsiteRecommendationAgent::assertPrompted(function (AgentPrompt $prompt) use ($finding, $evidence): bool {
            return $prompt->contains('CONTEXT_JSON:')
                && $prompt->contains('"id": '.$finding->id)
                && $prompt->contains('"id": '.$evidence->id)
                && $prompt->contains('sitemap')
                && ! $prompt->contains(str_repeat('X', 500));
        });
    }

    public function test_interpret_accepts_explicit_finding_ids(): void
    {
        [$asset, $finding, $evidence] = $this->seedWebsiteFindingWithEvidence();

        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'open',
            'title' => 'Ignored finding',
            'fingerprint' => 'ignored-finding',
        ]);

        WebsiteRecommendationAgent::fake([
            $this->structured($finding->id, $evidence->id, 'Focused interpretation for one finding.'),
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

        WebsiteRecommendationAgent::fake(function (): never {
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

    public function test_rejects_ungrounded_finding_ids_from_model_output(): void
    {
        [$asset, $finding, $evidence] = $this->seedWebsiteFindingWithEvidence();

        WebsiteRecommendationAgent::fake([
            [
                'executive_summary' => 'Keep only grounded finding references.',
                'overall_priority' => 'medium',
                'context_observations' => [],
                'finding_interpretations' => [
                    [
                        'finding_id' => $finding->id,
                        'evidence_ids' => [$evidence->id],
                        'explanation' => 'Valid grounded cause.',
                        'business_relevance' => 'Valid impact.',
                        'likely_contributors' => ['Missing sitemap'],
                        'uncertainty' => 'medium',
                        'suggested_priority' => 'medium',
                        'recommendation_draft' => [
                            'title' => 'Grounded draft',
                            'action' => 'Fix the sitemap.',
                            'rationale' => 'Matches finding '.$finding->id,
                            'effort' => 'low',
                        ],
                        'dependencies' => [],
                        'success_signal' => 'ok',
                        'failure_signal' => 'bad',
                        'watch_metrics' => [],
                    ],
                    [
                        'finding_id' => 999999,
                        'evidence_ids' => [$evidence->id],
                        'explanation' => 'Invented finding.',
                        'business_relevance' => 'Should fail validation.',
                        'likely_contributors' => [],
                        'uncertainty' => 'high',
                        'suggested_priority' => 'critical',
                        'recommendation_draft' => [
                            'title' => 'Invented draft',
                            'action' => 'Do not keep this.',
                            'rationale' => 'Ungrounded.',
                            'effort' => 'low',
                        ],
                        'dependencies' => [],
                        'success_signal' => 'n/a',
                        'failure_signal' => 'n/a',
                        'watch_metrics' => [],
                    ],
                ],
                'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
            ],
        ])->preventStrayPrompts();

        $run = app(WebsiteAiInsightService::class)->interpret($asset);
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, Recommendation::query()->count());
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

    /**
     * @return array<string, mixed>
     */
    private function structured(int $findingId, int $evidenceId, string $summary = 'Sitemap is missing, which limits crawl discovery.'): array
    {
        return [
            'executive_summary' => $summary,
            'overall_priority' => 'medium',
            'context_observations' => [],
            'finding_interpretations' => [
                [
                    'finding_id' => $findingId,
                    'evidence_ids' => [$evidenceId],
                    'explanation' => 'No readable sitemap.xml was returned for the host.',
                    'business_relevance' => 'Search engines may discover pages more slowly.',
                    'likely_contributors' => ['Missing sitemap'],
                    'uncertainty' => 'medium',
                    'suggested_priority' => 'medium',
                    'recommendation_draft' => [
                        'title' => 'Publish a valid XML sitemap',
                        'action' => 'Publish https://example.com/sitemap.xml as a well-formed urlset.',
                        'rationale' => 'Evidence #'.$evidenceId.' shows sitemap availability failed.',
                        'effort' => 'low',
                    ],
                    'dependencies' => [],
                    'success_signal' => 'Sitemap returns 200',
                    'failure_signal' => 'Still 404',
                    'watch_metrics' => ['GSC coverage'],
                ],
            ],
            'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
        ];
    }
}
