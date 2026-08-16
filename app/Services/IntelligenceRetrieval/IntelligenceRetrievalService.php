<?php

namespace App\Services\IntelligenceRetrieval;

use App\Contracts\IntelligenceMemory\SkillKnowledgeContextProvider;
use App\Enums\IntelligenceMatchReason;
use App\Enums\IntelligenceMemoryLayer;
use App\Enums\IntelligenceRetrievalDecision;
use App\Enums\IntelligenceRetrievalReasonCode;
use App\Enums\IntelligenceSourceAuthority;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\BrandIntelligence\BrandIntelligenceContextReadService;
use App\Support\Ai\EvidencePack;
use App\Support\IntelligenceMemory\AgentMemoryPermissionCatalog;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\BrandMemoryScope;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\SkillMemoryContractResolver;
use App\Support\IntelligenceRetrieval\Dto\BrandExperienceContextItem;
use App\Support\IntelligenceRetrieval\Dto\IntelligenceContextPack;
use App\Support\IntelligenceRetrieval\Dto\RetrievalSectionDecision;
use App\Support\IntelligenceRetrieval\Dto\SectorPatternContextItem;
use App\Support\IntelligenceRetrieval\Dto\SkillKnowledgeContextItem;
use App\Support\IntelligenceRetrieval\Dto\TypedMemoryContextPack;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;
use App\Support\IntelligenceRetrieval\SkillRetrievalContract;
use App\Support\Skills\SkillRegistry;

/**
 * Canonical server-side Intelligence Retrieval orchestrator (Prompt 54).
 *
 * The LLM participates in NONE of the access/selection steps.
 * No embeddings, vector DB, fine-tuning, provider calls, or Agent memory tools.
 */
final class IntelligenceRetrievalService
{
    public function __construct(
        private readonly SkillMemoryContractResolver $skillMemoryContractResolver,
        private readonly AgentMemoryPermissionCatalog $agentMemoryPermissionCatalog,
        private readonly BrandExperienceRetriever $brandExperienceRetriever,
        private readonly SectorPatternRetriever $sectorPatternRetriever,
        private readonly RelevantGoalRetriever $relevantGoalRetriever,
        private readonly SkillKnowledgeContextProvider $skillKnowledgeContextProvider,
        private readonly BrandIntelligenceContextReadService $brandIntelligenceContextReadService,
        private readonly SkillRegistry $skillRegistry,
    ) {}

    /**
     * @param  array{
     *     explicit_goal_ids?: list<int>,
     *     market_code?: string|null,
     *     channel?: string|null,
     *     current_brand_context?: array<string, mixed>|null,
     *     skill_memory_contract_override?: SkillMemoryContract|null,
     *     agent_permission_override?: AgentMemoryPermission|null,
     *     retrieval_contract_override?: SkillRetrievalContract|null
     * }  $options
     */
    public function retrieve(
        string $agentDefinitionSignature,
        string $skillDefinitionSignature,
        int $customerId,
        int $brandId,
        ?EvidencePack $evidencePack = null,
        ?DigitalAsset $digitalAsset = null,
        array $options = [],
    ): IntelligenceContextPack {
        $brand = Brand::query()->with('customer')->findOrFail($brandId);
        if ((int) $brand->customer_id !== $customerId) {
            throw new \InvalidArgumentException('Brand/Customer scope mismatch.');
        }

        $memoryContract = $options['skill_memory_contract_override']
            ?? $this->skillMemoryContractResolver->resolve($skillDefinitionSignature);

        $agentPermission = $options['agent_permission_override']
            ?? $this->agentMemoryPermissionCatalog->forSignature($agentDefinitionSignature);

        $retrievalContract = $options['retrieval_contract_override']
            ?? $this->buildRetrievalContract($skillDefinitionSignature, $memoryContract);

        $decisions = [];

        // Current Brand context
        $currentBrand = $options['current_brand_context']
            ?? $this->resolveCurrentBrandContext($brand, $digitalAsset);
        $decisions[] = new RetrievalSectionDecision(
            section: 'current_brand',
            decision: $currentBrand === []
                ? IntelligenceRetrievalDecision::Unavailable
                : IntelligenceRetrievalDecision::Included,
            authority: IntelligenceSourceAuthority::CurrentCanonicalContext,
            safeMetadata: ['bounded' => true],
        );

        // Goals
        $goalResult = $this->relevantGoalRetriever->retrieve(
            brand: $brand,
            contract: $retrievalContract,
            explicitGoalIds: $options['explicit_goal_ids'] ?? [],
        );
        $decisions[] = $goalResult['decision'];

        // Evidence — reused Prompt 50 pack (never substituted by Memory)
        $decisions[] = new RetrievalSectionDecision(
            section: 'evidence',
            decision: $evidencePack !== null
                ? IntelligenceRetrievalDecision::Included
                : IntelligenceRetrievalDecision::Unavailable,
            authority: IntelligenceSourceAuthority::CurrentCanonicalEvidence,
            safeMetadata: [
                'memory_cannot_substitute' => true,
                'sector_cannot_substitute' => true,
                'skill_knowledge_cannot_substitute' => true,
            ],
        );

        // Exact Skill context (this SkillRun only — not full catalog)
        $skillContext = $this->resolveExactSkillContext($skillDefinitionSignature);
        $decisions[] = new RetrievalSectionDecision(
            section: 'exact_skill',
            decision: $skillContext === []
                ? IntelligenceRetrievalDecision::Unavailable
                : IntelligenceRetrievalDecision::Included,
            matchReasons: [IntelligenceMatchReason::SkillExplicitReference->value],
            authority: IntelligenceSourceAuthority::GeneralSkillKnowledge,
            safeMetadata: ['full_skill_catalog_sent' => false],
        );

        // Brand Experience
        $brandItems = [];
        if ($memoryContract !== null && $memoryContract->requests(IntelligenceMemoryLayer::Brand)) {
            if (! $agentPermission->allows(IntelligenceMemoryLayer::Brand)) {
                $decisions[] = new RetrievalSectionDecision(
                    section: 'brand_experience',
                    decision: IntelligenceRetrievalDecision::NotAllowed,
                    reasonCodes: [IntelligenceRetrievalReasonCode::AgentLayerNotAllowed->value],
                    authority: IntelligenceSourceAuthority::HistoricalBrandExperience,
                );
            } else {
                $brandResult = $this->brandExperienceRetriever->retrieve(
                    scope: new BrandMemoryScope($customerId, $brandId),
                    contract: $retrievalContract,
                    filters: [
                        'goal_ids' => array_map(
                            static fn (array $g): int => (int) $g['id'],
                            $goalResult['goals']
                        ),
                        'market_code' => $options['market_code'] ?? null,
                        'channel' => $options['channel'] ?? null,
                    ],
                );
                $brandItems = $brandResult['items'];
                $decisions[] = $brandResult['decision'];
            }
        } else {
            $decisions[] = new RetrievalSectionDecision(
                section: 'brand_experience',
                decision: IntelligenceRetrievalDecision::NotRequested,
                reasonCodes: [IntelligenceRetrievalReasonCode::SkillDoesNotRequest->value],
                authority: IntelligenceSourceAuthority::HistoricalBrandExperience,
            );
        }

        // Sector patterns
        $sectorItems = [];
        if ($memoryContract !== null && $memoryContract->requests(IntelligenceMemoryLayer::Sector)) {
            $sectorResult = $this->sectorPatternRetriever->retrieve(
                brand: $brand,
                contract: $retrievalContract,
                agentAllowsSector: $agentPermission->allows(IntelligenceMemoryLayer::Sector),
                filters: ['channel' => $options['channel'] ?? null],
            );
            $sectorItems = $sectorResult['items'];
            $decisions[] = $sectorResult['decision'];
        } else {
            $decisions[] = new RetrievalSectionDecision(
                section: 'sector_pattern',
                decision: IntelligenceRetrievalDecision::NotRequested,
                reasonCodes: [IntelligenceRetrievalReasonCode::SkillDoesNotRequest->value],
                authority: IntelligenceSourceAuthority::PrivacyAggregatedSectorContext,
            );
        }

        // Skill knowledge
        $skillItems = [];
        if ($memoryContract !== null && $memoryContract->requests(IntelligenceMemoryLayer::Skill)) {
            if (! $agentPermission->allows(IntelligenceMemoryLayer::Skill)) {
                $decisions[] = new RetrievalSectionDecision(
                    section: 'skill_knowledge',
                    decision: IntelligenceRetrievalDecision::NotAllowed,
                    reasonCodes: [IntelligenceRetrievalReasonCode::AgentLayerNotAllowed->value],
                    authority: IntelligenceSourceAuthority::GeneralSkillKnowledge,
                );
            } else {
                $req = $memoryContract->requirementFor(IntelligenceMemoryLayer::Skill);
                $bound = min(
                    IntelligenceRetrievalPolicy::HARD_MAX_SKILL_KNOWLEDGE,
                    $req?->maximumRetrievalCount > 0 ? $req->maximumRetrievalCount : 5,
                );
                $refs = $this->skillKnowledgeContextProvider->listGeneralKnowledgeReferences(
                    $skillDefinitionSignature,
                    $bound
                );
                foreach ($refs as $ref) {
                    $skillItems[] = new SkillKnowledgeContextItem(
                        opaqueRef: (string) ($ref['artifact_id'] ?? 'skill'),
                        citation: (string) ($ref['citation'] ?? ''),
                        revision: isset($ref['revision']) ? (string) $ref['revision'] : null,
                        matchReasons: [IntelligenceMatchReason::SkillExplicitReference->value],
                    );
                }
                $decisions[] = new RetrievalSectionDecision(
                    section: 'skill_knowledge',
                    decision: $skillItems === []
                        ? IntelligenceRetrievalDecision::Unavailable
                        : IntelligenceRetrievalDecision::Included,
                    reasonCodes: $skillItems === []
                        ? [IntelligenceRetrievalReasonCode::KnowledgeReferenceUnavailable->value]
                        : [],
                    matchReasons: [IntelligenceMatchReason::SkillExplicitReference->value],
                    candidateCount: count($refs),
                    selectedCount: count($skillItems),
                    authority: IntelligenceSourceAuthority::GeneralSkillKnowledge,
                    safeMetadata: ['customer_data' => false, 'live_web' => false],
                );
            }
        } else {
            $decisions[] = new RetrievalSectionDecision(
                section: 'skill_knowledge',
                decision: IntelligenceRetrievalDecision::NotRequested,
                reasonCodes: [IntelligenceRetrievalReasonCode::SkillDoesNotRequest->value],
                authority: IntelligenceSourceAuthority::GeneralSkillKnowledge,
            );
        }

        $memoryFingerprint = $this->fingerprintMemory($brandItems, $sectorItems, $skillItems);
        $memoryPack = new TypedMemoryContextPack(
            customerId: $customerId,
            brandId: $brandId,
            agentDefinitionSignature: $agentDefinitionSignature,
            skillDefinitionSignature: $skillDefinitionSignature,
            brandExperiences: $brandItems,
            sectorPatterns: $sectorItems,
            skillKnowledge: $skillItems,
            decisions: array_values(array_filter(
                $decisions,
                static fn (RetrievalSectionDecision $d): bool => in_array($d->section, [
                    'brand_experience',
                    'sector_pattern',
                    'skill_knowledge',
                ], true)
            )),
            retrievalPolicyVersion: IntelligenceRetrievalPolicy::VERSION,
            contextFingerprint: $memoryFingerprint,
        );

        // Budget check on serialized memory (never drop required Evidence — Evidence is separate)
        $serialized = json_encode($memoryPack->toArray());
        $bytes = is_string($serialized) ? strlen($serialized) : 0;
        if ($bytes > IntelligenceRetrievalPolicy::HARD_MAX_MEMORY_SERIALIZED_BYTES) {
            $decisions[] = new RetrievalSectionDecision(
                section: 'context_budget',
                decision: IntelligenceRetrievalDecision::Blocked,
                reasonCodes: [IntelligenceRetrievalReasonCode::ContextBudgetExceeded->value],
                safeMetadata: [
                    'serialized_bytes' => $bytes,
                    'hard_max' => IntelligenceRetrievalPolicy::HARD_MAX_MEMORY_SERIALIZED_BYTES,
                    'required_evidence_never_evicted' => true,
                ],
            );
            // Deterministic reduction: clear optional memory rather than truncate Evidence
            $memoryPack = new TypedMemoryContextPack(
                customerId: $customerId,
                brandId: $brandId,
                agentDefinitionSignature: $agentDefinitionSignature,
                skillDefinitionSignature: $skillDefinitionSignature,
                brandExperiences: [],
                sectorPatterns: [],
                skillKnowledge: [],
                decisions: $memoryPack->decisions,
                retrievalPolicyVersion: IntelligenceRetrievalPolicy::VERSION,
                contextFingerprint: hash('sha256', 'budget_cleared|'.$memoryFingerprint),
            );
        }

        $retrievalFingerprint = hash('sha256', implode('|', [
            IntelligenceRetrievalPolicy::VERSION,
            $agentDefinitionSignature,
            $skillDefinitionSignature,
            (string) $customerId,
            (string) $brandId,
            $evidencePack?->contextFingerprint ?? 'no_evidence',
            $memoryPack->contextFingerprint,
            implode(',', array_map(static fn (array $g): string => (string) $g['id'], $goalResult['goals'])),
        ]));

        return new IntelligenceContextPack(
            customerId: $customerId,
            brandId: $brandId,
            agentDefinitionSignature: $agentDefinitionSignature,
            skillDefinitionSignature: $skillDefinitionSignature,
            currentBrandContext: $currentBrand,
            evidencePack: $evidencePack,
            relevantGoals: $goalResult['goals'],
            skillContext: $skillContext,
            memoryContextPack: $memoryPack,
            decisions: $decisions,
            retrievalMetadata: [
                'policy' => IntelligenceRetrievalPolicy::snapshot(),
                'retrieval_contract' => $retrievalContract->toArray(),
                'agent_layers_allowed' => array_map(
                    static fn (IntelligenceMemoryLayer $l): string => $l->value,
                    $agentPermission->allowedLayers ?? []
                ),
                'fine_tuning' => false,
                'embeddings' => false,
                'vector_db' => false,
                'llm_ranking' => false,
                'numeric_relevance_score' => null,
                'provider_calls_during_retrieval' => 0,
            ],
            retrievalFingerprint: $retrievalFingerprint,
        );
    }

    private function buildRetrievalContract(string $skillSignature, ?SkillMemoryContract $memoryContract): SkillRetrievalContract
    {
        return new SkillRetrievalContract(
            skillSignature: $skillSignature,
            memoryContract: $memoryContract ?? new SkillMemoryContract($skillSignature, []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCurrentBrandContext(Brand $brand, ?DigitalAsset $digitalAsset): array
    {
        $bic = null;
        try {
            $dto = $this->brandIntelligenceContextReadService->for($brand);
            $bic = method_exists($dto, 'toArray') ? $dto->toArray() : null;
        } catch (\Throwable) {
            $bic = null;
        }

        return [
            'brand_id' => (int) $brand->id,
            'customer_id' => (int) $brand->customer_id,
            'sector' => $brand->sector,
            'digital_asset' => $digitalAsset !== null ? [
                'id' => (int) $digitalAsset->id,
                'type' => $digitalAsset->type,
                'name' => $digitalAsset->name,
            ] : null,
            'brand_intelligence' => $bic,
            'authority' => IntelligenceSourceAuthority::CurrentCanonicalContext->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveExactSkillContext(string $skillDefinitionSignature): array
    {
        foreach ($this->skillRegistry->all() as $skill) {
            if ($skill->signature() === $skillDefinitionSignature) {
                return [
                    'signature' => $skill->signature(),
                    'version' => $skill->version,
                    'name' => $skill->name ?? null,
                    'full_catalog_not_included' => true,
                ];
            }
        }

        return [
            'signature' => $skillDefinitionSignature,
            'full_catalog_not_included' => true,
        ];
    }

    /**
     * @param  list<BrandExperienceContextItem>  $brandItems
     * @param  list<SectorPatternContextItem>  $sectorItems
     * @param  list<SkillKnowledgeContextItem>  $skillItems
     */
    private function fingerprintMemory(array $brandItems, array $sectorItems, array $skillItems): string
    {
        $parts = [
            IntelligenceRetrievalPolicy::VERSION,
            implode(',', array_map(static fn ($i) => (string) $i->experienceRevisionId, $brandItems)),
            implode(',', array_map(static fn ($i) => $i->artifact->artifactStableKey.':'.$i->artifact->revisionNumber, $sectorItems)),
            implode(',', array_map(static fn ($i) => $i->opaqueRef.':'.($i->revision ?? ''), $skillItems)),
        ];

        return hash('sha256', implode('|', $parts));
    }
}
