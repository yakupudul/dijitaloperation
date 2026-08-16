<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\IntelligenceMemoryAccessPolicy as IntelligenceMemoryAccessPolicyContract;
use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Enums\IntelligenceMemoryLayer;
use App\Enums\MemoryAccessDenialReason;
use App\Support\IntelligenceMemory\AgentMemoryPermissionCatalog;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\EffectiveMemoryAccess;
use App\Support\IntelligenceMemory\Dto\MemoryAccessDecision;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\SkillMemoryContractResolver;

/**
 * Central memory access policy (Prompt 51).
 *
 * Computes EffectiveMemoryAccess intersection. Does not retrieve content.
 */
final class IntelligenceMemoryAccessPolicy implements IntelligenceMemoryAccessPolicyContract
{
    public function __construct(
        private readonly AgentMemoryPermissionCatalog $agentMemoryPermissionCatalog,
        private readonly SkillMemoryContractResolver $skillMemoryContractResolver,
        private readonly SectorLearningPrivacyGate $sectorLearningPrivacyGate,
    ) {}

    public function resolveAgentPermission(string $agentDefinitionSignature): AgentMemoryPermission
    {
        return $this->agentMemoryPermissionCatalog->forSignature($agentDefinitionSignature);
    }

    public function resolveSkillContract(string $skillDefinitionSignature): ?SkillMemoryContract
    {
        return $this->skillMemoryContractResolver->resolve($skillDefinitionSignature);
    }

    public function evaluateEffectiveAccess(
        string $agentDefinitionSignature,
        string $skillDefinitionSignature,
        int $customerId,
        int $brandId,
        ?SectorIdentityRef $sectorIdentity = null,
        ?SectorPrivacyGateDecision $privacyDecision = null,
        ?AgentMemoryPermission $agentPermissionOverride = null,
        ?SkillMemoryContract $skillContractOverride = null,
    ): EffectiveMemoryAccess {
        $agentPermission = $agentPermissionOverride
            ?? $this->resolveAgentPermission($agentDefinitionSignature);
        $skillContract = $skillContractOverride
            ?? $this->resolveSkillContract($skillDefinitionSignature);

        if ($skillContract === null || $skillContract->requestedLayers() === []) {
            return new EffectiveMemoryAccess(
                grantedLayers: [],
                denialReasons: [MemoryAccessDenialReason::SkillDeclaresNoMemory],
                layerDetails: [
                    'default' => 'Skill with no Memory Contract receives no Memory.',
                ],
                retrievalImplemented: false,
            );
        }

        $granted = [];
        $denials = [];
        $details = [];

        foreach ($skillContract->requestedLayers() as $layer) {
            $decision = $this->decideLayerAccess(
                layer: $layer,
                agentDefinitionSignature: $agentDefinitionSignature,
                skillDefinitionSignature: $skillDefinitionSignature,
                customerId: $customerId,
                brandId: $brandId,
                memoryBrandId: $brandId,
                memoryCustomerId: $customerId,
                sectorIdentity: $sectorIdentity,
                privacyDecision: $privacyDecision,
                artifactValidityActive: true,
                agentPermissionOverride: $agentPermission,
                skillContractOverride: $skillContract,
            );

            $details[$layer->value] = $decision->toArray();

            if ($decision->allowed) {
                $granted[] = $layer;
            } else {
                foreach ($decision->denialReasons as $reason) {
                    $denials[] = $reason;
                }
            }
        }

        return new EffectiveMemoryAccess(
            grantedLayers: $granted,
            denialReasons: array_values(array_unique($denials, SORT_REGULAR)),
            layerDetails: $details,
            retrievalImplemented: false,
        );
    }

    public function decideLayerAccess(
        IntelligenceMemoryLayer $layer,
        string $agentDefinitionSignature,
        string $skillDefinitionSignature,
        int $customerId,
        int $brandId,
        int $memoryBrandId,
        int $memoryCustomerId,
        ?SectorIdentityRef $sectorIdentity = null,
        ?SectorPrivacyGateDecision $privacyDecision = null,
        bool $artifactValidityActive = true,
        ?AgentMemoryPermission $agentPermissionOverride = null,
        ?SkillMemoryContract $skillContractOverride = null,
    ): MemoryAccessDecision {
        $skillContract = $skillContractOverride
            ?? $this->resolveSkillContract($skillDefinitionSignature);
        $agentPermission = $agentPermissionOverride
            ?? $this->resolveAgentPermission($agentDefinitionSignature);

        if ($skillContract === null || ! $skillContract->requests($layer)) {
            return MemoryAccessDecision::deny(
                $layer,
                $skillContract === null
                    ? MemoryAccessDenialReason::SkillDeclaresNoMemory
                    : MemoryAccessDenialReason::SkillDoesNotRequestLayer,
            );
        }

        if (! $agentPermission->allows($layer)) {
            return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::AgentDoesNotAllowLayer);
        }

        if ($customerId !== $memoryCustomerId) {
            return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::CustomerScopeMismatch);
        }

        if ($layer === IntelligenceMemoryLayer::Brand) {
            if ($brandId !== $memoryBrandId) {
                return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::BrandScopeMismatch);
            }

            if (! $artifactValidityActive) {
                return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::ValidityNotActive);
            }

            return MemoryAccessDecision::allow(
                $layer,
                'Brand scope matched; content retrieval owned by Prompt 54; Brand Experience owned by Prompt 52.',
            );
        }

        if ($layer === IntelligenceMemoryLayer::Sector) {
            if ($sectorIdentity === null || ! $sectorIdentity->isPresent()) {
                return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::SectorIdentityMissing);
            }

            $privacy = $privacyDecision ?? $this->sectorLearningPrivacyGate->qualify(
                $sectorIdentity,
                ['contributing_brand_count' => 0],
            );

            if (! $privacy->isEligible()) {
                return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::SectorPrivacyNotQualified);
            }

            if (! $artifactValidityActive) {
                return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::ValidityNotActive);
            }

            return MemoryAccessDecision::allow(
                $layer,
                'Sector policy intersection passed; retrieval Prompt 54; Sector Learning Prompt 53.',
            );
        }

        // Skill layer
        if (! $artifactValidityActive) {
            return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::ValidityNotActive);
        }

        return MemoryAccessDecision::allow(
            $layer,
            'Skill Memory references general methodology only; retrieval Prompt 54.',
        );
    }

    public function assertBrandScope(int $executionBrandId, int $memoryBrandId): MemoryAccessDecision
    {
        if ($executionBrandId !== $memoryBrandId) {
            return MemoryAccessDecision::deny(
                IntelligenceMemoryLayer::Brand,
                MemoryAccessDenialReason::CrossBrandForbidden,
                MemoryAccessDenialReason::BrandScopeMismatch,
            );
        }

        return MemoryAccessDecision::allow(IntelligenceMemoryLayer::Brand, 'Exact Brand ID match.');
    }

    public function assertWriteAllowed(
        IntelligenceMemoryLayer $layer,
        string $actorKind,
    ): MemoryAccessDecision {
        $normalized = strtolower(trim($actorKind));

        if (in_array($normalized, ['agent', 'ai', 'llm', 'skill_run', 'agent_run'], true)) {
            return MemoryAccessDecision::deny(
                $layer,
                MemoryAccessDenialReason::AiDirectWriteForbidden,
                MemoryAccessDenialReason::WriteForbidden,
            );
        }

        if (in_array($normalized, ['task_listener', 'activity_listener', 'brand_service', 'recommendation_listener'], true)) {
            return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::WriteForbidden);
        }

        // Prompt 52/53 writers are the only future allowed routes; Prompt 51 still denies content writes.
        if (in_array($normalized, ['prompt_52', 'brand_experience_service', 'prompt_53', 'sector_learning_service', 'skill_curator'], true)) {
            return MemoryAccessDecision::deny(
                $layer,
                MemoryAccessDenialReason::LayerNotImplemented,
            );
        }

        return MemoryAccessDecision::deny($layer, MemoryAccessDenialReason::WriteForbidden);
    }
}
