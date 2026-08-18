<?php

namespace App\Support\IntelligenceMemory\Dto;

/**
 * Future Memory Context Pack (Prompt 54). Distinct from EvidencePack.
 *
 * Prompt 51 ships an empty pack factory only — no Agent prompt injection.
 *
 * @phpstan-type LayerRef array{
 *     layer: string,
 *     artifact_id: string|null,
 *     revision: string|null,
 *     citation: string|null
 * }
 */
final class MemoryContextPack
{
    /**
     * @param  list<LayerRef>  $brandRefs
     * @param  list<LayerRef>  $sectorRefs
     * @param  list<LayerRef>  $skillRefs
     * @param  list<string>  $retrievalNotes
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $brandId,
        public readonly string $agentDefinitionSignature,
        public readonly string $skillDefinitionSignature,
        public readonly array $brandRefs = [],
        public readonly array $sectorRefs = [],
        public readonly array $skillRefs = [],
        public readonly array $retrievalNotes = [],
        public readonly string $retrievalPolicyVersion = 'not_implemented',
        public readonly string $contextFingerprint = '',
    ) {}

    public static function empty(
        int $customerId,
        int $brandId,
        string $agentDefinitionSignature,
        string $skillDefinitionSignature,
        string ...$notes,
    ): self {
        return new self(
            customerId: $customerId,
            brandId: $brandId,
            agentDefinitionSignature: $agentDefinitionSignature,
            skillDefinitionSignature: $skillDefinitionSignature,
            retrievalNotes: array_values($notes),
            retrievalPolicyVersion: 'prompt_51_architecture_only',
            contextFingerprint: hash('sha256', implode('|', [
                'memory_pack_empty',
                (string) $customerId,
                (string) $brandId,
                $agentDefinitionSignature,
                $skillDefinitionSignature,
            ])),
        );
    }

    public function isEmpty(): bool
    {
        return $this->brandRefs === [] && $this->sectorRefs === [] && $this->skillRefs === [];
    }

    /**
     * @return array{
     *     customer_id: int,
     *     brand_id: int,
     *     agent_definition_signature: string,
     *     skill_definition_signature: string,
     *     brand_refs: list<LayerRef>,
     *     sector_refs: list<LayerRef>,
     *     skill_refs: list<LayerRef>,
     *     retrieval_notes: list<string>,
     *     retrieval_policy_version: string,
     *     context_fingerprint: string,
     *     empty: bool
     * }
     */
    public function toManifestArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'agent_definition_signature' => $this->agentDefinitionSignature,
            'skill_definition_signature' => $this->skillDefinitionSignature,
            'brand_refs' => $this->brandRefs,
            'sector_refs' => $this->sectorRefs,
            'skill_refs' => $this->skillRefs,
            'retrieval_notes' => $this->retrievalNotes,
            'retrieval_policy_version' => $this->retrievalPolicyVersion,
            'context_fingerprint' => $this->contextFingerprint,
            'empty' => $this->isEmpty(),
        ];
    }
}
