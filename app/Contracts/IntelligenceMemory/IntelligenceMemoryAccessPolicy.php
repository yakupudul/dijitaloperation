<?php

namespace App\Contracts\IntelligenceMemory;

use App\Enums\IntelligenceMemoryLayer;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\EffectiveMemoryAccess;
use App\Support\IntelligenceMemory\Dto\MemoryAccessDecision;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;

interface IntelligenceMemoryAccessPolicy
{
    public function resolveAgentPermission(string $agentDefinitionSignature): AgentMemoryPermission;

    public function resolveSkillContract(string $skillDefinitionSignature): ?SkillMemoryContract;

    public function evaluateEffectiveAccess(
        string $agentDefinitionSignature,
        string $skillDefinitionSignature,
        int $customerId,
        int $brandId,
        ?SectorIdentityRef $sectorIdentity = null,
        ?SectorPrivacyGateDecision $privacyDecision = null,
        ?AgentMemoryPermission $agentPermissionOverride = null,
        ?SkillMemoryContract $skillContractOverride = null,
    ): EffectiveMemoryAccess;

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
    ): MemoryAccessDecision;

    public function assertBrandScope(int $executionBrandId, int $memoryBrandId): MemoryAccessDecision;

    /**
     * Rejects mixed privacy classes and generic unrestricted memory writes.
     */
    public function assertWriteAllowed(
        IntelligenceMemoryLayer $layer,
        string $actorKind,
    ): MemoryAccessDecision;
}
