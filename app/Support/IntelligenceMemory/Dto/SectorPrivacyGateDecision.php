<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\SectorPrivacyDisposition;

/**
 * Sector privacy gate decision (Prompt 51 contract; Prompt 53 owns policy rules).
 *
 * No magic privacy_score.
 */
final class SectorPrivacyGateDecision
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $safeMetadata  must not include contributor Brand/Customer IDs
     */
    public function __construct(
        public readonly SectorPrivacyDisposition $disposition,
        public readonly array $reasons = [],
        public readonly ?string $policyVersion = null,
        public readonly array $safeMetadata = [],
    ) {
        foreach (['customer_id', 'brand_id', 'customer_ids', 'brand_ids', 'contributor_ids'] as $forbidden) {
            if (array_key_exists($forbidden, $this->safeMetadata)) {
                throw new \InvalidArgumentException(
                    "SectorPrivacyGateDecision.safeMetadata must not contain {$forbidden}."
                );
            }
        }
    }

    public function isEligible(): bool
    {
        return $this->disposition->isEligible();
    }

    /**
     * @return array{
     *     disposition: string,
     *     eligible: bool,
     *     reasons: list<string>,
     *     policy_version: string|null,
     *     safe_metadata: array<string, mixed>
     * }
     */
    public function toConsumerSafeArray(): array
    {
        return [
            'disposition' => $this->disposition->value,
            'eligible' => $this->isEligible(),
            'reasons' => array_values($this->reasons),
            'policy_version' => $this->policyVersion,
            'safe_metadata' => $this->safeMetadata,
        ];
    }
}
