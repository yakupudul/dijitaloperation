<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;

/**
 * Per-layer Skill memory requirement (versioned with Skill Definition).
 */
final class SkillMemoryLayerRequirement
{
    /**
     * @param  list<string>  $allowedArtifactKinds
     */
    public function __construct(
        public readonly IntelligenceMemoryLayer $layer,
        public readonly string $purpose,
        public readonly bool $required = false,
        public readonly array $allowedArtifactKinds = [],
        public readonly ?string $scopeSemantics = null,
        public readonly ?string $temporalSemantics = null,
        public readonly int $maximumRetrievalCount = 0,
        public readonly bool $requiresPrivacyQualification = false,
    ) {
        if ($this->maximumRetrievalCount < 0) {
            throw new \InvalidArgumentException('maximumRetrievalCount must be >= 0.');
        }
    }

    /**
     * @return array{
     *     layer: string,
     *     purpose: string,
     *     required: bool,
     *     allowed_artifact_kinds: list<string>,
     *     scope_semantics: string|null,
     *     temporal_semantics: string|null,
     *     maximum_retrieval_count: int,
     *     requires_privacy_qualification: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'layer' => $this->layer->value,
            'purpose' => $this->purpose,
            'required' => $this->required,
            'allowed_artifact_kinds' => array_values($this->allowedArtifactKinds),
            'scope_semantics' => $this->scopeSemantics,
            'temporal_semantics' => $this->temporalSemantics,
            'maximum_retrieval_count' => $this->maximumRetrievalCount,
            'requires_privacy_qualification' => $this->requiresPrivacyQualification,
        ];
    }
}
