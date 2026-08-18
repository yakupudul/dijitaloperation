<?php

namespace App\Support\BrandExperiences\Dto;

use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceSupportStatus;

/**
 * Deterministic Evidence Quality assessment — no numeric score.
 *
 * @phpstan-type AssessmentArray array{
 *     support_status: string,
 *     reason_codes: list<string>,
 *     policy_version: string,
 *     assessed_at: string,
 *     causality_status: string,
 *     dimensions: array{
 *         support_status: string,
 *         action_confirmation_status: string,
 *         temporal_compatibility: string,
 *         outcome_support_status: string,
 *         conflict_status: string,
 *         causality_status: string
 *     }
 * }
 */
final class BrandExperienceEvidenceQualityAssessment
{
    public const string POLICY_VERSION = 'brand_experience_quality_v1';

    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, string>  $dimensions
     */
    public function __construct(
        public readonly BrandExperienceSupportStatus $supportStatus,
        public readonly array $reasonCodes,
        public readonly string $policyVersion = self::POLICY_VERSION,
        public readonly string $assessedAt = '',
        public readonly BrandExperienceCausalityStatus $causalityStatus = BrandExperienceCausalityStatus::CausalityNotEstablished,
        public readonly array $dimensions = [],
    ) {
        if (isset($this->dimensions['score']) || isset($this->dimensions['confidence'])) {
            throw new \InvalidArgumentException('Numeric quality/confidence scores are forbidden.');
        }
    }

    /**
     * @return AssessmentArray
     */
    public function toArray(): array
    {
        return [
            'support_status' => $this->supportStatus->value,
            'reason_codes' => array_values($this->reasonCodes),
            'policy_version' => $this->policyVersion,
            'assessed_at' => $this->assessedAt !== '' ? $this->assessedAt : now()->toIso8601String(),
            'causality_status' => $this->causalityStatus->value,
            'dimensions' => [
                'support_status' => $this->dimensions['support_status'] ?? $this->supportStatus->value,
                'action_confirmation_status' => $this->dimensions['action_confirmation_status'] ?? 'not_assessed',
                'temporal_compatibility' => $this->dimensions['temporal_compatibility'] ?? 'not_assessed',
                'outcome_support_status' => $this->dimensions['outcome_support_status'] ?? 'not_assessed',
                'conflict_status' => $this->dimensions['conflict_status'] ?? 'none',
                'causality_status' => $this->causalityStatus->value,
            ],
        ];
    }
}
