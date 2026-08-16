<?php

namespace Tests\Feature;

use App\Contracts\IntelligenceMemory\IntelligenceMemoryAccessPolicy;
use App\Contracts\IntelligenceMemory\IntelligenceMemoryGateway;
use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Contracts\IntelligenceMemory\SkillKnowledgeContextProvider;
use App\Enums\IntelligenceMemoryLayer;
use App\Enums\MemoryAccessDenialReason;
use App\Enums\MemoryQualityState;
use App\Enums\MemorySourceKind;
use App\Enums\MemoryValidityState;
use App\Enums\SectorPrivacyDisposition;
use App\Models\Brand;
use App\Models\Customer;
use App\Services\IntelligenceMemory\IntelligenceMemoryArchitectureAuditor;
use App\Services\IntelligenceMemory\IntelligenceMemoryAuthority;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\MemoryCandidate;
use App\Support\IntelligenceMemory\Dto\MemoryContextRequest;
use App\Support\IntelligenceMemory\Dto\MemoryProvenance;
use App\Support\IntelligenceMemory\Dto\MemoryValidity;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\Dto\SkillMemoryLayerRequirement;
use App\Support\IntelligenceMemory\SkillMemoryCustomerDataGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IntelligenceMemoryArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_three_primary_memory_layers_exist(): void
    {
        $cases = IntelligenceMemoryLayer::cases();
        $this->assertCount(3, $cases);
        $this->assertSame(
            ['brand', 'sector', 'skill'],
            array_map(static fn (IntelligenceMemoryLayer $layer): string => $layer->value, $cases),
        );
        $this->assertSame(['brand', 'sector', 'skill'], app(IntelligenceMemoryArchitectureAuditor::class)->primaryMemoryLayers());
    }

    public function test_no_generic_memories_table_or_vector_dependencies(): void
    {
        $auditor = app(IntelligenceMemoryArchitectureAuditor::class);
        $this->assertSame([], $auditor->forbiddenGenericMemoryTables());
        $this->assertSame([], $auditor->forbiddenVectorDependencySignals());
        $this->assertSame([], $auditor->forbiddenAgentMemoryToolClasses());
    }

    public function test_skill_with_no_memory_contract_receives_no_memory(): void
    {
        $policy = app(IntelligenceMemoryAccessPolicy::class);
        $effective = $policy->evaluateEffectiveAccess(
            agentDefinitionSignature: 'website-seo-analyst@1.0.0',
            skillDefinitionSignature: 'website.technical-seo-analysis@1.1.0',
            customerId: 1,
            brandId: 1,
            agentPermissionOverride: new AgentMemoryPermission(
                'website-seo-analyst@1.0.0',
                [IntelligenceMemoryLayer::Brand, IntelligenceMemoryLayer::Sector, IntelligenceMemoryLayer::Skill],
            ),
        );

        $this->assertTrue($effective->isEmpty());
        $this->assertContains(MemoryAccessDenialReason::SkillDeclaresNoMemory, $effective->denialReasons);
    }

    public function test_agent_cannot_expand_skill_memory_contract(): void
    {
        $policy = app(IntelligenceMemoryAccessPolicy::class);
        $skillContract = new SkillMemoryContract('skill@1', [
            new SkillMemoryLayerRequirement(IntelligenceMemoryLayer::Brand, 'history', maximumRetrievalCount: 1),
        ]);

        $effective = $policy->evaluateEffectiveAccess(
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
            customerId: 1,
            brandId: 1,
            agentPermissionOverride: AgentMemoryPermission::none('agent@1'),
            skillContractOverride: $skillContract,
        );

        $this->assertTrue($effective->isEmpty());
        $this->assertFalse($effective->grants(IntelligenceMemoryLayer::Brand));
        $this->assertContains(MemoryAccessDenialReason::AgentDoesNotAllowLayer, $effective->denialReasons);
    }

    public function test_brand_a_memory_cannot_resolve_for_brand_b(): void
    {
        $gateway = app(IntelligenceMemoryGateway::class);
        $decision = $gateway->resolveBrandMemoryReferenceForExecution(
            executionCustomerId: 10,
            executionBrandId: 100,
            memoryCustomerId: 10,
            memoryBrandId: 200,
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
        );

        $this->assertFalse($decision->allowed);
        $this->assertContains(MemoryAccessDenialReason::BrandScopeMismatch, $decision->denialReasons);
    }

    public function test_same_customer_different_brand_still_forbidden(): void
    {
        $policy = app(IntelligenceMemoryAccessPolicy::class);
        $decision = $policy->assertBrandScope(1, 2);
        $this->assertFalse($decision->allowed);
        $this->assertContains(MemoryAccessDenialReason::CrossBrandForbidden, $decision->denialReasons);
    }

    public function test_customer_isolation(): void
    {
        $policy = app(IntelligenceMemoryAccessPolicy::class);
        $contract = new SkillMemoryContract('skill@1', [
            new SkillMemoryLayerRequirement(IntelligenceMemoryLayer::Brand, 'history', maximumRetrievalCount: 1),
        ]);
        $decision = $policy->decideLayerAccess(
            layer: IntelligenceMemoryLayer::Brand,
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
            customerId: 1,
            brandId: 10,
            memoryBrandId: 10,
            memoryCustomerId: 2,
            agentPermissionOverride: new AgentMemoryPermission('agent@1', [IntelligenceMemoryLayer::Brand]),
            skillContractOverride: $contract,
        );

        $this->assertFalse($decision->allowed);
        $this->assertContains(MemoryAccessDenialReason::CustomerScopeMismatch, $decision->denialReasons);
    }

    public function test_same_sector_does_not_grant_cross_brand_memory(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brandA = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);

        $resolver = app(SectorIdentityResolver::class);
        $this->assertSame('dental', $resolver->resolveForBrand($brandA)->code);
        $this->assertSame('dental', $resolver->resolveForBrand($brandB)->code);

        $gateway = app(IntelligenceMemoryGateway::class);
        $decision = $gateway->resolveBrandMemoryReferenceForExecution(
            executionCustomerId: (int) $customer->id,
            executionBrandId: (int) $brandA->id,
            memoryCustomerId: (int) $customer->id,
            memoryBrandId: (int) $brandB->id,
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
        );

        $this->assertFalse($decision->allowed);
    }

    public function test_one_brand_cannot_become_sector_memory(): void
    {
        $gate = app(SectorLearningPrivacyGate::class);
        $decision = $gate->rejectSingleBrandAsSectorLearning(1);
        $this->assertFalse($decision->isEligible());
        $this->assertSame(SectorPrivacyDisposition::BlockedOneBrandInsufficient, $decision->disposition);
    }

    public function test_raw_customer_data_cannot_enter_sector_candidate(): void
    {
        $gate = app(SectorLearningPrivacyGate::class);
        $identity = new SectorIdentityRef('dental', 'brand.sector');
        $decision = $gate->qualify($identity, [
            'brand_id' => 12,
            'contributing_brand_count' => 20,
        ]);
        $this->assertFalse($decision->isEligible());
        $this->assertSame(SectorPrivacyDisposition::BlockedRawCustomerData, $decision->disposition);
    }

    public function test_sector_consumer_payload_cannot_include_contributor_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SectorPrivacyGateDecision(
            disposition: SectorPrivacyDisposition::Eligible,
            safeMetadata: ['brand_ids' => [1, 2, 3]],
        );
    }

    public function test_sector_unknown_blocks_access_without_ai_inference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SectorIdentityRef('dental', 'ai', aiInferred: true);
    }

    public function test_skill_memory_rejects_customer_and_brand_ids(): void
    {
        $guard = app(SkillMemoryCustomerDataGuard::class);
        $this->expectException(InvalidArgumentException::class);
        $guard->assertNoCustomerOrBrandIdentifiers(['customer_id' => 9, 'citation' => 'x']);
    }

    public function test_skill_knowledge_provider_returns_only_general_references(): void
    {
        $provider = app(SkillKnowledgeContextProvider::class);
        $refs = $provider->listGeneralKnowledgeReferences('website.technical-seo-analysis@1.1.0');
        foreach ($refs as $ref) {
            $this->assertArrayNotHasKey('customer_id', $ref);
            $this->assertArrayNotHasKey('brand_id', $ref);
            $this->assertSame(MemorySourceKind::SkillDefinition->value, $ref['source_kind']);
        }
    }

    public function test_current_goal_outranks_historical_brand_memory(): void
    {
        $authority = app(IntelligenceMemoryAuthority::class);
        $resolved = $authority->resolveCurrentGoalPriority([
            'goal' => 'Netherlands',
            'brand_memory' => 'Germany',
            'sector_pattern' => 'WhatsApp',
        ]);
        $this->assertSame('Netherlands', $resolved);
        $this->assertFalse($authority->memoryMayOverrideCurrentCanonicalFact());
        $this->assertFalse($authority->sectorMayOverrideBrandCurrentContext());
    }

    public function test_no_magic_memory_quality_score_enums(): void
    {
        foreach (MemoryQualityState::cases() as $state) {
            $this->assertDoesNotMatchRegularExpression('/^\d+(\.\d+)?$/', $state->value);
        }
        $this->assertFalse(defined(MemoryQualityState::class.'::Confidence'));
    }

    public function test_provenance_is_consumer_safe_and_does_not_expose_contributors(): void
    {
        $provenance = new MemoryProvenance(
            layer: IntelligenceMemoryLayer::Sector,
            sourceKind: MemorySourceKind::SectorAggregation,
            sourceIdentity: 'sector_learning:dental:v0',
            policyVersion: 'prompt_51_boundary_only',
            qualityState: MemoryQualityState::Aggregated,
            validityState: MemoryValidityState::NeedsReview,
            consumerCitation: 'privacy-qualified MoxDOP cohort observation',
        );
        $array = $provenance->toConsumerSafeArray();
        $this->assertFalse($array['contributor_ids_visible_to_consumer']);
        $this->assertArrayNotHasKey('brand_ids', $array);
        $this->assertArrayNotHasKey('customer_ids', $array);
    }

    public function test_temporal_validity_distinguishes_active_from_historical(): void
    {
        $active = new MemoryValidity(MemoryValidityState::Active, effectiveAt: '2026-01-01');
        $historical = new MemoryValidity(MemoryValidityState::Historical, sourceOccurredAt: '2024-01-01');
        $this->assertTrue($active->isEligibleForCurrentAgentContext());
        $this->assertFalse($historical->isEligibleForCurrentAgentContext());
    }

    public function test_ai_cannot_directly_write_trusted_memory(): void
    {
        $policy = app(IntelligenceMemoryAccessPolicy::class);
        foreach ([IntelligenceMemoryLayer::Brand, IntelligenceMemoryLayer::Sector, IntelligenceMemoryLayer::Skill] as $layer) {
            $decision = $policy->assertWriteAllowed($layer, 'agent');
            $this->assertFalse($decision->allowed);
            $this->assertContains(MemoryAccessDenialReason::AiDirectWriteForbidden, $decision->denialReasons);
        }

        $candidate = new MemoryCandidate('brand', 'run-1', ['note' => 'worth remembering']);
        $this->assertFalse($candidate->isTrustedMemory());
        $this->assertSame('memory_candidate', $candidate->status);
    }

    public function test_gateway_returns_empty_memory_pack_without_retrieval(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);

        $gateway = app(IntelligenceMemoryGateway::class);
        $request = new MemoryContextRequest(
            agentDefinitionSignature: 'website-seo-analyst@1.0.0',
            skillDefinitionSignature: 'website.technical-seo-analysis@1.1.0',
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        );

        $pack = $gateway->resolveMemoryContextPack($request);
        $this->assertTrue($pack->isEmpty());
        $this->assertSame('prompt_51_architecture_only', $pack->retrievalPolicyVersion);

        $manifest = $gateway->evaluate($request);
        $this->assertFalse($manifest->retrievalImplemented);
    }

    public function test_sector_privacy_gate_does_not_invent_cohort_threshold(): void
    {
        $gate = app(SectorLearningPrivacyGate::class);
        $decision = $gate->qualify(new SectorIdentityRef('dental', 'brand.sector'), [
            'contributing_brand_count' => 50,
        ]);
        $this->assertFalse($decision->isEligible());
        $this->assertSame(SectorPrivacyDisposition::BlockedPipelineNotImplemented, $decision->disposition);
        $this->assertStringContainsString('Prompt 53', implode(' ', $decision->reasons));
        $this->assertArrayNotHasKey('minimum_cohort', $decision->safeMetadata);
    }

    public function test_both_allow_brand_memory_still_requires_exact_brand_match(): void
    {
        $policy = app(IntelligenceMemoryAccessPolicy::class);
        $contract = new SkillMemoryContract('skill@1', [
            new SkillMemoryLayerRequirement(IntelligenceMemoryLayer::Brand, 'history', maximumRetrievalCount: 2),
        ]);
        $permission = new AgentMemoryPermission('agent@1', [IntelligenceMemoryLayer::Brand]);

        $ok = $policy->decideLayerAccess(
            layer: IntelligenceMemoryLayer::Brand,
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
            customerId: 1,
            brandId: 10,
            memoryBrandId: 10,
            memoryCustomerId: 1,
            agentPermissionOverride: $permission,
            skillContractOverride: $contract,
        );
        $this->assertTrue($ok->allowed);

        $denied = $policy->decideLayerAccess(
            layer: IntelligenceMemoryLayer::Brand,
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
            customerId: 1,
            brandId: 10,
            memoryBrandId: 11,
            memoryCustomerId: 1,
            agentPermissionOverride: $permission,
            skillContractOverride: $contract,
        );
        $this->assertFalse($denied->allowed);
    }

    public function test_sector_access_requires_identity_and_privacy_even_when_contracts_allow(): void
    {
        $policy = app(IntelligenceMemoryAccessPolicy::class);
        $contract = new SkillMemoryContract('skill@1', [
            new SkillMemoryLayerRequirement(
                IntelligenceMemoryLayer::Sector,
                'cohort patterns',
                requiresPrivacyQualification: true,
                maximumRetrievalCount: 1,
            ),
        ]);
        $permission = new AgentMemoryPermission('agent@1', [IntelligenceMemoryLayer::Sector]);

        $missing = $policy->decideLayerAccess(
            layer: IntelligenceMemoryLayer::Sector,
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
            customerId: 1,
            brandId: 1,
            memoryBrandId: 1,
            memoryCustomerId: 1,
            sectorIdentity: new SectorIdentityRef(null, 'missing'),
            agentPermissionOverride: $permission,
            skillContractOverride: $contract,
        );
        $this->assertFalse($missing->allowed);
        $this->assertContains(MemoryAccessDenialReason::SectorIdentityMissing, $missing->denialReasons);

        $blockedPrivacy = $policy->decideLayerAccess(
            layer: IntelligenceMemoryLayer::Sector,
            agentDefinitionSignature: 'agent@1',
            skillDefinitionSignature: 'skill@1',
            customerId: 1,
            brandId: 1,
            memoryBrandId: 1,
            memoryCustomerId: 1,
            sectorIdentity: new SectorIdentityRef('dental', 'brand.sector'),
            privacyDecision: new SectorPrivacyGateDecision(SectorPrivacyDisposition::BlockedPrivacyNotQualified, ['not qualified']),
            agentPermissionOverride: $permission,
            skillContractOverride: $contract,
        );
        $this->assertFalse($blockedPrivacy->allowed);
        $this->assertContains(MemoryAccessDenialReason::SectorPrivacyNotQualified, $blockedPrivacy->denialReasons);
    }
}
