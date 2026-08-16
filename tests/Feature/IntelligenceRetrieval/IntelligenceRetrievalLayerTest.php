<?php

namespace Tests\Feature\IntelligenceRetrieval;

use App\Contracts\IntelligenceMemory\IntelligenceMemoryGateway;
use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceChannel;
use App\Enums\BrandExperienceOrigin;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Enums\IntelligenceMemoryLayer;
use App\Enums\IntelligenceRetrievalDecision;
use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningArtifactStatus;
use App\Enums\SectorLearningCohortBand;
use App\Enums\SectorPrivacyDisposition;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BrandExperienceRevision;
use App\Models\Customer;
use App\Models\SectorLearningArtifact;
use App\Models\SectorLearningRevision;
use App\Services\IntelligenceRetrieval\IntelligenceContextReferenceValidator;
use App\Services\IntelligenceRetrieval\IntelligenceRetrievalService;
use App\Support\BrandExperiences\BrandExperienceContextSnapshot;
use App\Support\BrandExperiences\Dto\BrandExperienceEvidenceQualityAssessment;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\MemoryContextRequest;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\Dto\SkillMemoryLayerRequirement;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;
use App\Support\IntelligenceRetrieval\SkillRetrievalContract;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntelligenceRetrievalLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrieval_policy_has_no_scores_or_vectors(): void
    {
        $snap = IntelligenceRetrievalPolicy::snapshot();
        $this->assertSame('intelligence_retrieval_v1', $snap['version']);
        $this->assertNull($snap['numeric_relevance_score']);
        $this->assertFalse($snap['embeddings']);
        $this->assertFalse($snap['vector_db']);
        $this->assertFalse($snap['fine_tuning']);
        $this->assertFalse($snap['llm_ranking']);
        $this->assertFalse($snap['silent_truncation']);
    }

    public function test_skill_without_memory_contract_receives_empty_memory(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);

        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            agentDefinitionSignature: 'website-seo-analyst@1.0.0',
            skillDefinitionSignature: 'website.technical-seo-analysis@1.1.0',
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        );

        $this->assertTrue($pack->memoryContextPack->isEmpty());
        $brandDecision = collect($pack->decisions)->firstWhere('section', 'brand_experience');
        $this->assertSame(IntelligenceRetrievalDecision::NotRequested, $brandDecision->decision);
    }

    public function test_same_brand_experience_retrieved_with_match_reasons(): void
    {
        [$customer, $brand] = $this->seedBrandWithExperience();
        $contract = $this->fullMemoryContract('skill@test');

        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            agentDefinitionSignature: 'agent@test',
            skillDefinitionSignature: 'skill@test',
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            options: [
                'skill_memory_contract_override' => $contract->memoryContract,
                'agent_permission_override' => new AgentMemoryPermission('agent@test', [
                    IntelligenceMemoryLayer::Brand,
                    IntelligenceMemoryLayer::Sector,
                    IntelligenceMemoryLayer::Skill,
                ]),
                'retrieval_contract_override' => $contract,
            ],
        );

        $this->assertNotEmpty($pack->memoryContextPack->brandExperiences);
        $item = $pack->memoryContextPack->brandExperiences[0];
        $this->assertContains('CONFIRMED_ELIGIBLE', $item->matchReasons);
        $this->assertSame('causality_not_established', $item->causalityStatus);
        $this->assertStringStartsWith('brand_experience:', $item->opaqueRef);
        $array = $pack->toManifestArray();
        $this->assertNull($array['sector_contributor_identities']);
        $this->assertNull($array['numeric_relevance_score']);
    }

    public function test_cross_brand_experience_not_retrieved(): void
    {
        [$customerA, $brandA] = $this->seedBrandWithExperience();
        $customerB = Customer::factory()->create(['industry' => 'dental']);
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id, 'sector' => 'dental']);
        $contract = $this->fullMemoryContract('skill@test');

        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            agentDefinitionSignature: 'agent@test',
            skillDefinitionSignature: 'skill@test',
            customerId: (int) $customerB->id,
            brandId: (int) $brandB->id,
            options: [
                'skill_memory_contract_override' => $contract->memoryContract,
                'agent_permission_override' => new AgentMemoryPermission('agent@test', [IntelligenceMemoryLayer::Brand]),
                'retrieval_contract_override' => $contract,
            ],
        );

        $this->assertTrue($pack->memoryContextPack->isEmpty() || $pack->memoryContextPack->brandExperiences === []);
        // Ensure Brand A's experience id never appears
        foreach ($pack->memoryContextPack->brandExperiences as $item) {
            $this->assertStringNotContainsString((string) $brandA->id, $item->opaqueRef);
        }
        unset($customerA);
    }

    public function test_same_customer_other_brand_forbidden(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brandA = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $this->createConfirmedExperience($brandA);
        $contract = $this->fullMemoryContract('skill@test');

        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            agentDefinitionSignature: 'agent@test',
            skillDefinitionSignature: 'skill@test',
            customerId: (int) $customer->id,
            brandId: (int) $brandB->id,
            options: [
                'skill_memory_contract_override' => $contract->memoryContract,
                'agent_permission_override' => new AgentMemoryPermission('agent@test', [IntelligenceMemoryLayer::Brand]),
                'retrieval_contract_override' => $contract,
            ],
        );

        $this->assertSame([], $pack->memoryContextPack->brandExperiences);
    }

    public function test_sector_retrieval_uses_released_artifacts_only(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $this->seedReleasedSectorArtifact('dental');
        $contract = $this->fullMemoryContract('skill@test');

        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            agentDefinitionSignature: 'agent@test',
            skillDefinitionSignature: 'skill@test',
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            options: [
                'skill_memory_contract_override' => $contract->memoryContract,
                'agent_permission_override' => new AgentMemoryPermission('agent@test', [
                    IntelligenceMemoryLayer::Sector,
                ]),
                'retrieval_contract_override' => $contract,
            ],
        );

        $this->assertNotEmpty($pack->memoryContextPack->sectorPatterns);
        $sectorArray = $pack->memoryContextPack->sectorPatterns[0]->toArray();
        $this->assertArrayNotHasKey('customer_id', $sectorArray['artifact']);
        $this->assertArrayNotHasKey('brand_id', $sectorArray['artifact']);
        $this->assertFalse($sectorArray['artifact']['industry_benchmark_claim']);
        $json = json_encode($pack->toPromptSections());
        $this->assertStringNotContainsString('"contributor', (string) $json);
        $this->assertStringNotContainsString('lineage', (string) $json);
    }

    public function test_agent_cannot_expand_skill_memory(): void
    {
        [$customer, $brand] = $this->seedBrandWithExperience();
        // Skill requests nothing; agent allows all
        $emptyContract = new SkillRetrievalContract(
            skillSignature: 'skill@test',
            memoryContract: new SkillMemoryContract('skill@test', []),
        );

        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            agentDefinitionSignature: 'agent@test',
            skillDefinitionSignature: 'skill@test',
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            options: [
                'skill_memory_contract_override' => $emptyContract->memoryContract,
                'agent_permission_override' => new AgentMemoryPermission('agent@test', [
                    IntelligenceMemoryLayer::Brand,
                    IntelligenceMemoryLayer::Sector,
                    IntelligenceMemoryLayer::Skill,
                ]),
                'retrieval_contract_override' => $emptyContract,
            ],
        );

        $this->assertTrue($pack->memoryContextPack->isEmpty());
    }

    public function test_fingerprint_deterministic(): void
    {
        [$customer, $brand] = $this->seedBrandWithExperience();
        $contract = $this->fullMemoryContract('skill@test');
        $options = [
            'skill_memory_contract_override' => $contract->memoryContract,
            'agent_permission_override' => new AgentMemoryPermission('agent@test', [
                IntelligenceMemoryLayer::Brand,
                IntelligenceMemoryLayer::Skill,
            ]),
            'retrieval_contract_override' => $contract,
        ];

        $a = app(IntelligenceRetrievalService::class)->retrieve(
            'agent@test',
            'skill@test',
            (int) $customer->id,
            (int) $brand->id,
            options: $options,
        );
        $b = app(IntelligenceRetrievalService::class)->retrieve(
            'agent@test',
            'skill@test',
            (int) $customer->id,
            (int) $brand->id,
            options: $options,
        );

        $this->assertSame($a->retrievalFingerprint, $b->retrievalFingerprint);
        $this->assertSame($a->memoryContextPack->contextFingerprint, $b->memoryContextPack->contextFingerprint);
    }

    public function test_memory_ref_cannot_masquerade_as_evidence(): void
    {
        $validator = app(IntelligenceContextReferenceValidator::class);
        $this->expectException(\InvalidArgumentException::class);
        $validator->assertMemoryCannotSatisfyEvidence('brand_experience:9');
    }

    public function test_unknown_memory_ref_rejected(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            'agent@test',
            'skill@test',
            (int) $customer->id,
            (int) $brand->id,
        );
        $result = app(IntelligenceContextReferenceValidator::class)->validate(
            $pack,
            claimedMemoryRefs: ['sector_artifact:not-real'],
        );
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_gateway_retrieval_implemented(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $gateway = app(IntelligenceMemoryGateway::class);
        $manifest = $gateway->evaluate(new MemoryContextRequest(
            agentDefinitionSignature: 'website-seo-analyst@1.0.0',
            skillDefinitionSignature: 'website.technical-seo-analysis@1.1.0',
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        ));
        $this->assertTrue($manifest->retrievalImplemented);
    }

    public function test_no_fine_tuning_or_vector_in_retrieval_services(): void
    {
        $dir = app_path('Services/IntelligenceRetrieval');
        foreach (scandir($dir) ?: [] as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $contents = (string) file_get_contents($dir.'/'.$file);
            // Strip comments/docblocks before scanning for forbidden APIs.
            $code = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
            $code = preg_replace('#//.*$#m', '', $code) ?? $code;
            $this->assertDoesNotMatchRegularExpression(
                '/\\\\OpenAI\\\\|createEmbedding\s*\(|pgvector|Pinecone::|Qdrant::|Weaviate::|Milvus::|->fineTunes?\s*\(/i',
                $code
            );
            $this->assertStringNotContainsString('cosine_similarity', $code);
        }
        $this->assertFalse(class_exists('App\\Services\\IntelligenceRetrieval\\RetrievalV2'));
        $this->assertFalse(class_exists('App\\Services\\CrossTenantQueryService'));
        $this->assertFalse(IntelligenceRetrievalPolicy::snapshot()['embeddings']);
        $this->assertFalse(IntelligenceRetrievalPolicy::snapshot()['vector_db']);
        $this->assertFalse(IntelligenceRetrievalPolicy::snapshot()['fine_tuning']);
    }

    public function test_exact_skill_only_not_full_catalog(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $contract = $this->fullMemoryContract('website.technical-seo-analysis@1.1.0');
        $pack = app(IntelligenceRetrievalService::class)->retrieve(
            'website-seo-analyst@1.0.0',
            'website.technical-seo-analysis@1.1.0',
            (int) $customer->id,
            (int) $brand->id,
            options: [
                'skill_memory_contract_override' => $contract->memoryContract,
                'agent_permission_override' => new AgentMemoryPermission('website-seo-analyst@1.0.0', [
                    IntelligenceMemoryLayer::Skill,
                ]),
                'retrieval_contract_override' => $contract,
            ],
        );
        $this->assertTrue($pack->skillContext['full_catalog_not_included'] ?? false);
        $this->assertLessThanOrEqual(1, count($pack->memoryContextPack->skillKnowledge));
    }

    /**
     * @return array{0: Customer, 1: Brand}
     */
    private function seedBrandWithExperience(): array
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $this->createConfirmedExperience($brand);

        return [$customer, $brand];
    }

    private function createConfirmedExperience(Brand $brand): BrandExperience
    {
        $quality = new BrandExperienceEvidenceQualityAssessment(
            supportStatus: BrandExperienceSupportStatus::Sufficient,
            reasonCodes: ['causality_not_established', 'temporal_order_valid'],
        );
        $context = new BrandExperienceContextSnapshot(
            brandId: (int) $brand->id,
            customerId: (int) $brand->customer_id,
        );
        $experience = BrandExperience::query()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'status' => BrandExperienceStatus::Confirmed,
            'origin' => BrandExperienceOrigin::OperatorCaptured,
        ]);
        $revision = BrandExperienceRevision::query()->create([
            'brand_experience_id' => $experience->id,
            'revision_number' => 1,
            'context_schema_version' => $context->schemaVersion,
            'context_snapshot' => $context->toArray(),
            'market_code' => 'DE',
            'channel' => BrandExperienceChannel::Website,
            'situation_summary' => 'Historical situation',
            'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed,
            'action_summary' => 'Historical action',
            'action_occurred_at' => now()->subDays(30),
            'outcome_summary' => 'Historical outcome',
            'outcome_observed_at' => now()->subDays(10),
            'outcome_clarity' => BrandExperienceOutcomeClarity::Favorable,
            'support_status' => $quality->supportStatus,
            'quality_assessment' => $quality->toArray(),
            'quality_policy_version' => $quality->policyVersion,
            'quality_assessed_at' => now(),
            'causality_status' => BrandExperienceCausalityStatus::CausalityNotEstablished,
        ]);
        $experience->forceFill(['current_revision_id' => $revision->id])->save();

        return $experience->fresh(['currentRevision']);
    }

    private function seedReleasedSectorArtifact(string $sectorCode): void
    {
        $artifact = SectorLearningArtifact::query()->create([
            'sector_code' => $sectorCode,
            'stable_key' => hash('sha256', 'test-sector-'.$sectorCode),
            'artifact_kind' => SectorLearningArtifactKind::ActionOutcomeAssociation,
            'status' => SectorLearningArtifactStatus::Active,
        ]);
        $revision = SectorLearningRevision::query()->create([
            'artifact_id' => $artifact->id,
            'revision_number' => 1,
            'status' => SectorLearningArtifactStatus::Active,
            'dimension_contract' => ['sector_code' => $sectorCode, 'dimensions' => ['sector_code']],
            'time_scope' => ['granularity' => 'month'],
            'metric_family' => 'outcome_clarity_distribution',
            'action_category' => null,
            'aggregate_result' => [
                'schema' => 'sector_aggregate_action_outcome_v1',
                'causality' => 'causality_not_established',
                'industry_benchmark_claim' => false,
                'cells' => [],
            ],
            'cohort_band' => SectorLearningCohortBand::Band5To9,
            'limitations' => ['MOXDOP_COHORT_OBSERVATION', 'OBSERVATIONAL_ONLY'],
            'privacy_policy_version' => SectorLearningPrivacyPolicy::VERSION,
            'aggregation_method_version' => 'sector_aggregation_v1',
            'projection_version' => 'sector_projection_v1',
            'aggregate_fingerprint' => hash('sha256', 'fp'),
            'observational_label' => 'MOXDOP_COHORT_OBSERVATION',
            'summary_text' => 'Privacy-qualified MoxDOP cohort observation.',
            'privacy_assessment' => [
                'disposition' => SectorPrivacyDisposition::Eligible->value,
                'reason_codes' => [],
                'privacy_score' => null,
            ],
            'internal_distinct_brands' => 5,
            'internal_distinct_customers' => 5,
        ]);
        $artifact->forceFill(['current_revision_id' => $revision->id])->save();
    }

    private function fullMemoryContract(string $signature): SkillRetrievalContract
    {
        $memory = new SkillMemoryContract($signature, [
            new SkillMemoryLayerRequirement(
                layer: IntelligenceMemoryLayer::Brand,
                purpose: 'history',
                maximumRetrievalCount: 5,
            ),
            new SkillMemoryLayerRequirement(
                layer: IntelligenceMemoryLayer::Sector,
                purpose: 'cohort',
                maximumRetrievalCount: 3,
                requiresPrivacyQualification: true,
            ),
            new SkillMemoryLayerRequirement(
                layer: IntelligenceMemoryLayer::Skill,
                purpose: 'methodology',
                maximumRetrievalCount: 2,
            ),
        ]);

        return new SkillRetrievalContract($signature, $memory);
    }
}
