<?php

namespace Tests\Feature;

use App\Contracts\Ai\AgentContextGateway;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Run;
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
use MoxDop\Website\Agents\WebsiteBrandDiscoveryAnalyst;
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
        ]);

        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-test-ai',
        ], $this->admin);
    }

    public function test_planner_abstains_when_all_skills_require_missing_evidence(): void
    {
        $profile = new AgentProfileDefinition(
            slug: 'test-abstain-agent',
            version: '1.0.0',
            name: 'Test Abstain Agent',
            module: 'search_demand',
            purpose: 'test',
            status: 'active',
            aiRouteKey: 'search_demand.clustering',
            skillSlugs: ['search-demand-clustering', 'search-query-classification'],
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
        $profile = WebsiteBrandDiscoveryAnalyst::definition();
        $plan = app(AgentExecutionPlanner::class)->plan($profile, ['website_public_site_summary']);

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
            'website.discovery_context',
            'route-sig',
            str_repeat('1', 64),
        );

        $this->assertSame((int) $brand->customer_id, $pack->customerId);
        $this->assertSame((int) $brand->id, $pack->brandId);
        $this->assertSame([7], $pack->evidenceIds());
        $this->assertTrue($pack->containsEvidenceId(7));
        $this->assertFalse($pack->containsEvidenceId(8));
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
            'module_id' => 'public-discovery',
            'status' => 'running',
        ]);

        $profile = WebsiteBrandDiscoveryAnalyst::definition();
        $plan = app(AgentExecutionPlanner::class)->plan($profile, ['website_public_site_summary']);

        $agentRun = app(AgentExecutionRecorder::class)->startFromPlan(
            $run,
            $asset,
            $profile,
            $plan,
            'website.discovery_context',
            'sig',
            str_repeat('9', 64),
        );

        $this->assertSame($run->id, $agentRun->run_id);
        $this->assertCount(count($profile->skillSlugs), $agentRun->skillExecutionRuns);
    }
}
