<?php

namespace Tests\Feature;

use App\Contracts\Ai\AgentContextGateway;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ai\AgentExecutionPlanner;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Ai\EvidencePack;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
