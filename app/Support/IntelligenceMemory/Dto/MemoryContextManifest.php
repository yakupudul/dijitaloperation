<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;

/**
 * Manifest of policy evaluation for a MemoryContextRequest (no content).
 *
 * @phpstan-type DecisionRow array{
 *     layer: string,
 *     allowed: bool,
 *     denial_reasons: list<string>,
 *     notes: list<string>
 * }
 */
final class MemoryContextManifest
{
    /**
     * @param  list<DecisionRow>  $decisions
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $brandId,
        public readonly string $agentDefinitionSignature,
        public readonly string $skillDefinitionSignature,
        public readonly array $decisions = [],
        public readonly array $notes = [],
        public readonly bool $retrievalImplemented = false,
    ) {}

    /**
     * @return list<IntelligenceMemoryLayer>
     */
    public function allowedLayers(): array
    {
        $layers = [];
        foreach ($this->decisions as $row) {
            if (($row['allowed'] ?? false) !== true) {
                continue;
            }
            $layer = IntelligenceMemoryLayer::tryFrom((string) ($row['layer'] ?? ''));
            if ($layer !== null) {
                $layers[] = $layer;
            }
        }

        return $layers;
    }

    /**
     * @return array{
     *     customer_id: int,
     *     brand_id: int,
     *     agent_definition_signature: string,
     *     skill_definition_signature: string,
     *     decisions: list<DecisionRow>,
     *     notes: list<string>,
     *     retrieval_implemented: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'agent_definition_signature' => $this->agentDefinitionSignature,
            'skill_definition_signature' => $this->skillDefinitionSignature,
            'decisions' => $this->decisions,
            'notes' => $this->notes,
            'retrieval_implemented' => $this->retrievalImplemented,
        ];
    }
}
