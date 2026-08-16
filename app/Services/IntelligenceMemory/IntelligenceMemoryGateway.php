<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\IntelligenceMemoryAccessPolicy;
use App\Contracts\IntelligenceMemory\IntelligenceMemoryGateway as IntelligenceMemoryGatewayContract;
use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Enums\IntelligenceMemoryLayer;
use App\Models\Brand;
use App\Services\IntelligenceRetrieval\IntelligenceRetrievalService;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\MemoryAccessDecision;
use App\Support\IntelligenceMemory\Dto\MemoryContextManifest;
use App\Support\IntelligenceMemory\Dto\MemoryContextPack;
use App\Support\IntelligenceMemory\Dto\MemoryContextRequest;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\Dto\SkillMemoryLayerRequirement;

/**
 * Central Intelligence Memory Gateway — policy + Prompt 54 retrieval.
 *
 * LLM must not call this; application resolves before inference.
 */
final class IntelligenceMemoryGateway implements IntelligenceMemoryGatewayContract
{
    public function __construct(
        private readonly IntelligenceMemoryAccessPolicy $accessPolicy,
        private readonly SectorIdentityResolver $sectorIdentityResolver,
        private readonly SectorLearningPrivacyGate $sectorLearningPrivacyGate,
        private readonly IntelligenceRetrievalService $intelligenceRetrievalService,
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
                'Prompt 54 retrieval implemented — structured deterministic selection only.',
                'effective_empty='.($effective->isEmpty() ? 'yes' : 'no'),
                'embeddings=false',
                'vector_db=false',
                'fine_tuning=false',
            ],
            retrievalImplemented: true,
        );
    }

    public function resolveMemoryContextPack(MemoryContextRequest $request): MemoryContextPack
    {
        $pack = $this->intelligenceRetrievalService->retrieve(
            agentDefinitionSignature: $request->agentDefinitionSignature,
            skillDefinitionSignature: $request->skillDefinitionSignature,
            customerId: $request->customerId,
            brandId: $request->brandId,
            evidencePack: null,
            digitalAsset: null,
            options: [],
        );

        return $pack->memoryContextPack->toLegacyMemoryContextPack();
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
