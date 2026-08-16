<?php

namespace App\Support\IntelligenceRetrieval\Dto;

use App\Enums\IntelligenceRetrievalDecision;
use App\Enums\IntelligenceSourceAuthority;

/**
 * One typed retrieval section decision with reason codes (no scores).
 */
final class RetrievalSectionDecision
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $matchReasons
     * @param  array<string, mixed>  $safeMetadata
     */
    public function __construct(
        public readonly string $section,
        public readonly IntelligenceRetrievalDecision $decision,
        public readonly array $reasonCodes = [],
        public readonly array $matchReasons = [],
        public readonly int $candidateCount = 0,
        public readonly int $selectedCount = 0,
        public readonly int $omittedCount = 0,
        public readonly ?IntelligenceSourceAuthority $authority = null,
        public readonly array $safeMetadata = [],
    ) {
        foreach (['customer_ids', 'brand_ids', 'contributor_ids', 'experience_ids'] as $forbidden) {
            if (array_key_exists($forbidden, $this->safeMetadata)) {
                throw new \InvalidArgumentException(
                    "RetrievalSectionDecision.safeMetadata must not contain {$forbidden}."
                );
            }
        }
    }

    public function blocksInference(): bool
    {
        return in_array($this->decision, [
            IntelligenceRetrievalDecision::RequiredMissing,
            IntelligenceRetrievalDecision::Blocked,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'decision' => $this->decision->value,
            'reason_codes' => array_values($this->reasonCodes),
            'match_reasons' => array_values($this->matchReasons),
            'candidate_count' => $this->candidateCount,
            'selected_count' => $this->selectedCount,
            'omitted_count' => $this->omittedCount,
            'authority' => $this->authority?->value,
            'safe_metadata' => $this->safeMetadata,
            'blocks_inference' => $this->blocksInference(),
        ];
    }
}
