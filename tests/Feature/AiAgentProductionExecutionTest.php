<?php

namespace Tests\Feature;

use App\Contracts\Ai\AgentContextGateway;
use App\Models\AgentExecutionRun;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Models\SkillExecutionRun;
use App\Models\User;
use App\Services\Ai\AgentExecutionPlanner;
use App\Services\Ai\AgentExecutionRecorder;
use App\Services\Ai\StructuredAgentOutputValidator;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Agents\AgentProfileDefinition;
use App\Support\Ai\AgentExecutionPlan;
use App\Support\Ai\EvidencePack;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use MoxDop\Website\Agents\WebsiteSeoAnalyst;
use MoxDop\Website\Ai\Agents\WebsiteRecommendationAgent;
use MoxDop\Website\Ai\WebsiteAiRecommendationConfig;
use MoxDop\Website\Ai\WebsiteAiRecommendationService;
use Tests\TestCase;

class AiAgentProductionExecutionTest extends TestCase
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
            'moxdop.openai.recommendation_model' => 'gpt-5-mini',
        ]);

        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-test-ai',
        ], $this->admin);
    }

    public function test_planner_marks_recommendation_framing_eligible_with_empty_required_evidence(): void
    {
        $profile = WebsiteSeoAnalyst::definition();
        $plan = app(AgentExecutionPlanner::class)->plan($profile, []);

        $this->assertSame(AgentExecutionPlan::READY, $plan->preInferenceStatus);
        $this->assertTrue($plan->shouldCallInference());
        $this->assertNotEmpty($plan->eligibleSkills);

        $framing = collect($plan->skillEvaluations)
            ->first(fn (array $row): bool => ($row['slug'] ?? '') === 'recommendation-framing');

        $this->assertIsArray($framing);
        $this->assertTrue($framing['eligible']);
    }

    public function test_planner_abstains_when_all_skills_require_missing_evidence(): void
    {
        $profile = new AgentProfileDefinition(
            slug: 'test-abstain-agent',
            version: '1.0.0',
            name: 'Test Abstain Agent',
            module: 'website',
            purpose: 'test',
            status: 'active',
            aiRouteKey: 'website.ai_guidance',
            skillSlugs: ['technical-seo-analysis', 'search-console-analysis'],
            allowedDataScope: [],
            allowedOperations: [],
            forbiddenOperations: [],
            outputContract: 'test',
            successCriteria: [],
        );

        $plan = app(AgentExecutionPlanner::class)->plan($profile, []);

        $this->assertSame(AgentExecutionPlan::ABSTAINED_PRE_INFERENCE, $plan->preInferenceStatus);
        $this->assertFalse($plan->shouldCallInference());
        $this->assertSame([], $plan->eligibleSkills);
        $this->assertNotEmpty($plan->blockedSkills);
        $this->assertNotNull($plan->blockReasonCode);
    }

    public function test_evidence_pack_manifest_and_contains_helpers(): void
    {
        $pack = new EvidencePack(
            customerId: 1,
            brandId: 2,
            digitalAssetId: 3,
            subjectType: 'website',
            agentSlug: 'website-seo-analyst',
            agentVersion: '1.0.0',
            skillSignatures: ['website.recommendation-framing@1.1.0'],
            routeKey: 'website.ai_guidance',
            routeSignature: 'sig',
            evidenceItems: [
                ['id' => 10, 'type' => 'page_html', 'fingerprint' => 'abc'],
                ['id' => 11, 'type' => 'robots_txt'],
            ],
            contextFingerprint: str_repeat('a', 64),
            inputFingerprint: str_repeat('b', 64),
            packedAt: now()->toIso8601String(),
        );

        $this->assertSame([10, 11], $pack->evidenceIds());
        $this->assertSame(['page_html', 'robots_txt'], $pack->evidenceTypes());
        $this->assertTrue($pack->containsEvidenceId(10));
        $this->assertFalse($pack->containsEvidenceId(99));
        $this->assertSame(3, $pack->toManifestArray()['digital_asset_id']);
    }

    public function test_structured_validator_rejects_out_of_pack_evidence_and_forbidden_keys(): void
    {
        $pack = new EvidencePack(
            customerId: null,
            brandId: null,
            digitalAssetId: 1,
            subjectType: 'website',
            agentSlug: 'website-seo-analyst',
            agentVersion: '1.0.0',
            skillSignatures: [],
            routeKey: 'website.ai_guidance',
            routeSignature: 'sig',
            evidenceItems: [['id' => 5, 'type' => 'page_html']],
            contextFingerprint: str_repeat('c', 64),
            inputFingerprint: str_repeat('d', 64),
            packedAt: now()->toIso8601String(),
        );

        $validator = app(StructuredAgentOutputValidator::class);

        $ok = $validator->validate([
            'executive_summary' => 'ok',
            'evidence_ids' => [5],
        ], $pack);
        $this->assertSame([5], $ok['evidence_ids']);

        $this->expectException(InvalidArgumentException::class);
        $validator->validate([
            'evidence_ids' => [5, 99],
        ], $pack);
    }

    public function test_structured_validator_rejects_chain_of_thought_and_magic_scores(): void
    {
        $pack = new EvidencePack(
            customerId: null,
            brandId: null,
            digitalAssetId: 1,
            subjectType: 'website',
            agentSlug: 'a',
            agentVersion: '1',
            skillSignatures: [],
            routeKey: 'r',
            routeSignature: 's',
            evidenceItems: [],
            contextFingerprint: str_repeat('e', 64),
            inputFingerprint: str_repeat('f', 64),
            packedAt: now()->toIso8601String(),
        );

        $validator = app(StructuredAgentOutputValidator::class);

        try {
            $validator->validate(['chain_of_thought' => 'secret'], $pack);
            $this->fail('Expected exception for chain_of_thought');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->expectException(InvalidArgumentException::class);
        $validator->validate(['seo_score' => 88], $pack);
    }

    public function test_context_gateway_builds_pack_from_redacted_context(): void
    {
        $brand = Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);
        $profile = WebsiteSeoAnalyst::definition();
        $plan = app(AgentExecutionPlanner::class)->plan($profile, ['page_html']);

        $pack = app(AgentContextGateway::class)->buildEvidencePackFromContext(
            $asset,
            $profile,
            $plan,
            [
                'evidence' => [
                    ['id' => 7, 'type' => 'page_html', 'evidence_fingerprint' => 'fp-7'],
                    ['id' => 8, 'type' => 'robots_txt'],
                ],
            ],
            [7],
            [1],
            'website.ai_guidance',
            'route-sig',
            str_repeat('1', 64),
        );

        $this->assertSame((int) $brand->customer_id, $pack->customerId);
        $this->assertSame((int) $brand->id, $pack->brandId);
        $this->assertSame([7], $pack->evidenceIds());
        $this->assertTrue($pack->containsEvidenceId(7));
        $this->assertFalse($pack->containsEvidenceId(8));
    }

    public function test_website_analyze_persists_agent_execution_run_with_fake_llm(): void
    {
        [$asset, $finding, $evidence] = $this->seedWebsiteContext();

        WebsiteRecommendationAgent::fake([
            [
                'executive_summary' => 'Grounded summary',
                'overall_priority' => 'medium',
                'context_observations' => [],
                'finding_interpretations' => [
                    [
                        'finding_id' => $finding->id,
                        'evidence_ids' => [$evidence->id],
                        'explanation' => 'Based on Evidence',
                        'business_relevance' => 'Relevant',
                        'likely_contributors' => ['Missing signal'],
                        'uncertainty' => 'low',
                        'suggested_priority' => 'medium',
                        'recommendation_draft' => [
                            'title' => 'Draft',
                            'action' => 'Do the thing',
                            'rationale' => 'Evidence-backed',
                            'effort' => 'low',
                        ],
                        'dependencies' => [],
                        'success_signal' => 'ok',
                        'failure_signal' => 'not ok',
                        'watch_metrics' => [],
                    ],
                ],
                'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
            ],
        ])->preventStrayPrompts();

        $result = app(WebsiteAiRecommendationService::class)->analyze($asset);

        $this->assertFalse($result['reused']);
        $this->assertSame('completed', $result['run']->status);

        $agentRun = AgentExecutionRun::query()->where('run_id', $result['run']->id)->first();
        $this->assertInstanceOf(AgentExecutionRun::class, $agentRun);
        $this->assertSame(WebsiteSeoAnalyst::SLUG, $agentRun->agent_slug);
        $this->assertSame(AgentExecutionPlan::READY, $agentRun->pre_inference_status);
        $this->assertSame(AgentExecutionRun::STATUS_COMPLETED, $agentRun->status);
        $this->assertGreaterThan(0, $agentRun->skillExecutionRuns()->count());
        $this->assertTrue(
            $agentRun->skillExecutionRuns()
                ->where('status', SkillExecutionRun::STATUS_VALIDATED)
                ->exists()
        );

        WebsiteRecommendationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('AGENT CONTRACT'));
    }

    public function test_recorder_start_from_plan_creates_skill_rows(): void
    {
        $brand = Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website-ai-insights',
            'status' => 'running',
        ]);

        $profile = WebsiteSeoAnalyst::definition();
        $plan = app(AgentExecutionPlanner::class)->plan($profile, ['page_html']);

        $agentRun = app(AgentExecutionRecorder::class)->startFromPlan(
            $run,
            $asset,
            $profile,
            $plan,
            'website.ai_guidance',
            'sig',
            str_repeat('9', 64),
        );

        $this->assertSame($run->id, $agentRun->run_id);
        $this->assertCount(count($profile->skillSlugs), $agentRun->skillExecutionRuns);
    }

    /**
     * @return array{0: DigitalAsset, 1: Finding, 2: Evidence}
     */
    private function seedWebsiteContext(): array
    {
        $brand = Brand::factory()->create();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://example.com',
            'domain' => 'example.com',
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
                'present' => false,
                'status_code' => 404,
            ],
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'fingerprint' => 'sitemap-xml-availability|host=example.com',
            'category' => 'indexability',
            'severity' => 'high',
            'title' => 'Sitemap missing or unreadable',
            'summary' => 'No readable sitemap.xml was found.',
            'confidence' => 0.7,
            'status' => 'open',
            'last_run_id' => $diagnosisRun->id,
        ]);

        return [$asset, $finding, $evidence];
    }
}
