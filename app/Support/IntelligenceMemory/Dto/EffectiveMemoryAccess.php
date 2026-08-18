<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;
use App\Enums\MemoryAccessDenialReason;

/**
 * Result of EffectiveMemoryAccess formula evaluation.
 *
 * EffectiveMemory =
 *   SkillRequestedMemory
 *   ∩ AgentAllowedMemory
 *   ∩ CurrentAuthorizedScope
 *   ∩ LayerSpecificPolicy
 *   ∩ CurrentValidity
 *   ∩ PrivacyQualification
 *   ∩ RetrievalSelection
 *
 * Prompt 51 evaluates policy intersection; Prompt 54 owns retrieval selection.
 */
final class EffectiveMemoryAccess
{
    /**
     * @param  list<IntelligenceMemoryLayer>  $grantedLayers
     * @param  list<MemoryAccessDenialReason>  $denialReasons
     * @param  array<string, mixed>  $layerDetails
     */
    public function __construct(
        public readonly array $grantedLayers = [],
        public readonly array $denialReasons = [],
        public readonly array $layerDetails = [],
        public readonly bool $retrievalImplemented = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->grantedLayers === [];
    }

    public function grants(IntelligenceMemoryLayer $layer): bool
    {
        return in_array($layer, $this->grantedLayers, true);
    }

    /**
     * @return array{
     *     granted_layers: list<string>,
     *     denial_reasons: list<string>,
     *     layer_details: array<string, mixed>,
     *     retrieval_implemented: bool,
     *     empty: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'granted_layers' => array_map(
                static fn (IntelligenceMemoryLayer $layer): string => $layer->value,
                $this->grantedLayers,
            ),
            'denial_reasons' => array_map(
                static fn (MemoryAccessDenialReason $reason): string => $reason->value,
                $this->denialReasons,
            ),
            'layer_details' => $this->layerDetails,
            'retrieval_implemented' => $this->retrievalImplemented,
            'empty' => $this->isEmpty(),
        ];
    }
}
