<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\IntelligenceMemoryAccessPolicy;
use App\Contracts\IntelligenceMemory\IntelligenceMemoryGateway as IntelligenceMemoryGatewayContract;
use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Enums\IntelligenceMemoryLayer;
use App\Models\Brand;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\MemoryAccessDecision;
use App\Support\IntelligenceMemory\Dto\MemoryContextManifest;
use App\Support\IntelligenceMemory\Dto\MemoryContextPack;
use App\Support\IntelligenceMemory\Dto\MemoryContextRequest;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\Dto\SkillMemoryLayerRequirement;

/**
 * Central Intelligence Memory Gateway — policy coordination, not central storage.
 *
 * Rejects table/model/SQL/DSL parameters by having no such API.
 * LLM must not call this; application resolves before inference (Prompt 54).
 * Prompt 51 returns empty MemoryContextPack (no Agent injection).
 */
final class IntelligenceMemoryGateway implements IntelligenceMemoryGatewayContract
{
    public function __construct(
        private readonly IntelligenceMemoryAccessPolicy $accessPolicy,
        private readonly SectorIdentityResolver $sectorIdentityResolver,
        private readonly SectorLearningPrivacyGate $sectorLearningPrivacyGate,
    ) {}

    public function evaluate(MemoryContextRequest $request): MemoryContextManifest
    {
        $brand = Brand::query()->find($request->brandId);
        $sectorIdentity = $brand !== null
            ? $this->sectorIdentityResolver->resolveForBrand($brand)
            : null;

        $privacy = $sectorIdentity !== null
            ? $this->sectorLearningPrivacyGate->qualify($sectorIdentity, [])
            : null;

        $effective = $this->accessPolicy->evaluateEffectiveAccess(
            agentDefinitionSignature: $request->agentDefinitionSignature,
            skillDefinitionSignature: $request->skillDefinitionSignature,
            customerId: $request->customerId,
            brandId: $request->brandId,
            sectorIdentity: $sectorIdentity,
            privacyDecision: $privacy,
        );

        $layers = $request->layer !== null
            ? [$request->layer]
            : ($request->requestedLayers ?? [
                IntelligenceMemoryLayer::Brand,
                IntelligenceMemoryLayer::Sector,
                IntelligenceMemoryLayer::Skill,
            ]);

        $decisions = [];
        foreach ($layers as $layer) {
            $decision = $this->accessPolicy->decideLayerAccess(
                layer: $layer,
                agentDefinitionSignature: $request->agentDefinitionSignature,
                skillDefinitionSignature: $request->skillDefinitionSignature,
                customerId: $request->customerId,
                brandId: $request->brandId,
                memoryBrandId: $request->brandId,
                memoryCustomerId: $request->customerId,
                sectorIdentity: $sectorIdentity,
                privacyDecision: $privacy,
            );
            $decisions[] = $decision->toArray();
        }

        return new MemoryContextManifest(
            customerId: $request->customerId,
            brandId: $request->brandId,
            agentDefinitionSignature: $request->agentDefinitionSignature,
            skillDefinitionSignature: $request->skillDefinitionSignature,
            decisions: $decisions,
            notes: [
                'Prompt 51 architecture/policy only — retrieval not implemented.',
                'effective_empty='.($effective->isEmpty() ? 'yes' : 'no'),
            ],
            retrievalImplemented: false,
        );
    }

    public function resolveMemoryContextPack(MemoryContextRequest $request): MemoryContextPack
    {
        $manifest = $this->evaluate($request);

        return MemoryContextPack::empty(
            $request->customerId,
            $request->brandId,
            $request->agentDefinitionSignature,
            $request->skillDefinitionSignature,
            'Memory Context Pack retrieval is Prompt 54.',
            'Prompt 51 returns empty pack; no Agent prompt injection.',
            'manifest_allowed_layers='.implode(',', array_map(
                static fn (IntelligenceMemoryLayer $layer): string => $layer->value,
                $manifest->allowedLayers(),
            )),
        );
    }

    /**
     * Explicit Brand isolation check used by architectural tests.
     */
    public function resolveBrandMemoryReferenceForExecution(
        int $executionCustomerId,
        int $executionBrandId,
        int $memoryCustomerId,
        int $memoryBrandId,
        string $agentDefinitionSignature,
        string $skillDefinitionSignature,
        ?SkillMemoryContract $skillContract = null,
        ?AgentMemoryPermission $agentPermission = null,
    ): MemoryAccessDecision {
        $contract = $skillContract ?? new SkillMemoryContract(
            $skillDefinitionSignature,
            [
                new SkillMemoryLayerRequirement(
                    layer: IntelligenceMemoryLayer::Brand,
                    purpose: 'test_brand_isolation',
                    required: false,
                    maximumRetrievalCount: 1,
                ),
            ],
        );

        return $this->accessPolicy->decideLayerAccess(
            layer: IntelligenceMemoryLayer::Brand,
            agentDefinitionSignature: $agentDefinitionSignature,
            skillDefinitionSignature: $skillDefinitionSignature,
            customerId: $executionCustomerId,
            brandId: $executionBrandId,
            memoryBrandId: $memoryBrandId,
            memoryCustomerId: $memoryCustomerId,
            agentPermissionOverride: $agentPermission ?? new AgentMemoryPermission(
                $agentDefinitionSignature,
                [IntelligenceMemoryLayer::Brand],
            ),
            skillContractOverride: $contract,
        );
    }
}
