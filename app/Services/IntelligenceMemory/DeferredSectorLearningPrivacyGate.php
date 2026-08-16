<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Enums\SectorPrivacyDisposition;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;

/**
 * Prompt 51 privacy gate stub: no usable Sector Memory until Prompt 53.
 *
 * Enforces architectural blocks (one-brand, raw data keys, missing sector)
 * without inventing a magic cohort threshold.
 */
final class DeferredSectorLearningPrivacyGate implements SectorLearningPrivacyGate
{
    public const string POLICY_VERSION = 'prompt_51_boundary_only';

    public function qualify(SectorIdentityRef $sectorIdentity, array $candidate): SectorPrivacyGateDecision
    {
        if (! $sectorIdentity->isPresent()) {
            return new SectorPrivacyGateDecision(
                disposition: SectorPrivacyDisposition::BlockedSectorUnknown,
                reasons: ['Sector identity missing; AI/text inference forbidden.'],
                policyVersion: self::POLICY_VERSION,
            );
        }

        if ($sectorIdentity->aiInferred) {
            return new SectorPrivacyGateDecision(
                disposition: SectorPrivacyDisposition::BlockedSectorUnknown,
                reasons: ['AI-inferred sector identity is forbidden.'],
                policyVersion: self::POLICY_VERSION,
            );
        }

        foreach (['customer_id', 'brand_id', 'customer_name', 'brand_name', 'domain', 'url', 'campaign_name', 'keyword', 'notes', 'free_text'] as $key) {
            if (array_key_exists($key, $candidate) && $candidate[$key] !== null && $candidate[$key] !== '') {
                return new SectorPrivacyGateDecision(
                    disposition: SectorPrivacyDisposition::BlockedRawCustomerData,
                    reasons: ["Candidate contains forbidden identifying key: {$key}."],
                    policyVersion: self::POLICY_VERSION,
                );
            }
        }

        if (($candidate['raw_provider_rows'] ?? false) === true
            || ($candidate['raw_evidence_copy'] ?? false) === true) {
            return new SectorPrivacyGateDecision(
                disposition: SectorPrivacyDisposition::BlockedRawCustomerData,
                reasons: ['Raw provider/Evidence data cannot enter Sector Memory directly.'],
                policyVersion: self::POLICY_VERSION,
            );
        }

        $brandCount = isset($candidate['contributing_brand_count'])
            ? (int) $candidate['contributing_brand_count']
            : null;

        if ($brandCount !== null && $brandCount <= 1) {
            return $this->rejectSingleBrandAsSectorLearning($brandCount);
        }

        return new SectorPrivacyGateDecision(
            disposition: SectorPrivacyDisposition::BlockedPipelineNotImplemented,
            reasons: [
                'Prompt 51 defines the privacy gate contract only.',
                'Usable Sector Learning requires Prompt 53 privacy qualification + aggregation.',
            ],
            policyVersion: self::POLICY_VERSION,
            safeMetadata: [
                'sector_code' => $sectorIdentity->code,
            ],
        );
    }

    public function rejectSingleBrandAsSectorLearning(int $brandCount): SectorPrivacyGateDecision
    {
        return new SectorPrivacyGateDecision(
            disposition: SectorPrivacyDisposition::BlockedOneBrandInsufficient,
            reasons: [
                'One Brand cannot become usable Sector Memory.',
                "Observed contributing_brand_count={$brandCount}.",
                'Exact minimum cohort threshold is owned by Prompt 53 (not hardcoded here).',
            ],
            policyVersion: self::POLICY_VERSION,
        );
    }
}
