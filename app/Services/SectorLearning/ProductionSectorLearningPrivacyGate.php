<?php

namespace App\Services\SectorLearning;

use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Enums\SectorLearningPrivacyReasonCode;
use App\Enums\SectorPrivacyDisposition;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;
use App\Support\SectorLearning\SectorLearningMetricRegistry;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;
use App\Support\SectorLearning\SectorLearningSafeDimensionRegistry;

/**
 * Production Sector privacy gate (Prompt 53).
 *
 * Deterministic disclosure-control. No privacy score. No AI.
 * Replaces Prompt 51 DeferredSectorLearningPrivacyGate for usable Sector Learning.
 */
final class ProductionSectorLearningPrivacyGate implements SectorLearningPrivacyGate
{
    public const string POLICY_VERSION = SectorLearningPrivacyPolicy::VERSION;

    public function qualify(SectorIdentityRef $sectorIdentity, array $candidate): SectorPrivacyGateDecision
    {
        if (! $sectorIdentity->isPresent()) {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedSectorUnknown,
                [SectorLearningPrivacyReasonCode::SectorUnknown->value],
            );
        }

        if ($sectorIdentity->aiInferred) {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedSectorUnknown,
                [SectorLearningPrivacyReasonCode::SectorUnknown->value, 'AI-inferred sector identity is forbidden.'],
            );
        }

        foreach (SectorLearningPrivacyPolicy::BLOCKED_IDENTIFIER_KEYS as $key) {
            if (array_key_exists($key, $candidate) && $candidate[$key] !== null && $candidate[$key] !== '') {
                return $this->blocked(
                    SectorPrivacyDisposition::BlockedRawCustomerData,
                    [SectorLearningPrivacyReasonCode::RawIdentifierPresent->value, "Forbidden key: {$key}"],
                );
            }
        }

        if (($candidate['raw_provider_rows'] ?? false) === true
            || ($candidate['raw_evidence_copy'] ?? false) === true) {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedRawCustomerData,
                [SectorLearningPrivacyReasonCode::RawIdentifierPresent->value],
            );
        }

        if (($candidate['free_text'] ?? null) !== null && $candidate['free_text'] !== '') {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedFreeText,
                [SectorLearningPrivacyReasonCode::FreeTextPresent->value],
            );
        }

        if (($candidate['raw_keyword'] ?? null) !== null) {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedRawCustomerData,
                [SectorLearningPrivacyReasonCode::RawKeywordPresent->value],
            );
        }

        if (($candidate['raw_creative'] ?? null) !== null) {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedRawCustomerData,
                [SectorLearningPrivacyReasonCode::RawCreativePresent->value],
            );
        }

        if (($candidate['raw_url'] ?? null) !== null) {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedRawCustomerData,
                [SectorLearningPrivacyReasonCode::RawUrlPresent->value],
            );
        }

        $brandCount = isset($candidate['contributing_brand_count'])
            ? (int) $candidate['contributing_brand_count']
            : null;
        $customerCount = isset($candidate['contributing_customer_count'])
            ? (int) $candidate['contributing_customer_count']
            : null;

        if ($brandCount !== null && $brandCount <= 1) {
            return $this->rejectSingleBrandAsSectorLearning($brandCount);
        }

        // Incomplete candidate (e.g. gateway probe): not Eligible — no silent pipeline invent.
        if ($brandCount === null || $customerCount === null) {
            return $this->blocked(
                SectorPrivacyDisposition::BlockedPrivacyNotQualified,
                [
                    SectorLearningPrivacyReasonCode::IncompleteCandidate->value,
                    'Usable Sector Learning requires privacy-qualified projected contributions and cohort counts.',
                ],
            );
        }

        $reasons = [];

        if ($brandCount < SectorLearningPrivacyPolicy::MIN_DISTINCT_BRANDS) {
            $reasons[] = SectorLearningPrivacyReasonCode::InsufficientDistinctBrands->value;
        }
        if ($customerCount < SectorLearningPrivacyPolicy::MIN_DISTINCT_CUSTOMERS) {
            $reasons[] = SectorLearningPrivacyReasonCode::InsufficientDistinctCustomers->value;
        }

        $numeric = (bool) ($candidate['requires_numeric_cohort'] ?? false);
        if ($numeric) {
            if ($brandCount < SectorLearningPrivacyPolicy::MIN_NUMERIC_AGGREGATE_BRANDS
                || $customerCount < SectorLearningPrivacyPolicy::MIN_NUMERIC_AGGREGATE_CUSTOMERS) {
                $reasons[] = SectorLearningPrivacyReasonCode::InsufficientNumericCohort->value;
            }
        }

        $maxBrandShare = isset($candidate['max_brand_effective_share'])
            ? (float) $candidate['max_brand_effective_share']
            : null;
        if ($maxBrandShare !== null
            && $maxBrandShare > SectorLearningPrivacyPolicy::MAX_SINGLE_BRAND_EFFECTIVE_SHARE + 1e-9) {
            $reasons[] = SectorLearningPrivacyReasonCode::DominantBrandContribution->value;
        }

        $maxCustomerShare = isset($candidate['max_customer_effective_share'])
            ? (float) $candidate['max_customer_effective_share']
            : null;
        if ($maxCustomerShare !== null
            && $maxCustomerShare > SectorLearningPrivacyPolicy::MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE + 1e-9) {
            $reasons[] = SectorLearningPrivacyReasonCode::DominantCustomerContribution->value;
        }

        $dimensions = $candidate['dimensions'] ?? [];
        if (is_array($dimensions)) {
            foreach ($dimensions as $dimension) {
                if (! is_string($dimension)) {
                    continue;
                }
                if (! SectorLearningSafeDimensionRegistry::isAllowed($dimension)) {
                    $reasons[] = SectorLearningPrivacyReasonCode::UnsafeDimension->value;
                }
            }
        }

        if (($candidate['city'] ?? null) !== null || ($candidate['postcode'] ?? null) !== null) {
            $reasons[] = SectorLearningPrivacyReasonCode::IdentifyingDimension->value;
        }

        if (($candidate['exact_date'] ?? null) !== null) {
            $reasons[] = SectorLearningPrivacyReasonCode::UnsafeExactValue->value;
        }

        if (($candidate['rare_combination'] ?? false) === true) {
            $reasons[] = SectorLearningPrivacyReasonCode::RareDimensionCombination->value;
        }

        $metricFamily = $candidate['metric_family'] ?? null;
        if (is_string($metricFamily) && $metricFamily !== '' && ! SectorLearningMetricRegistry::isAllowed($metricFamily)) {
            $reasons[] = SectorLearningPrivacyReasonCode::IncompatibleMetric->value;
        }

        if (($candidate['mixed_currency'] ?? false) === true) {
            $reasons[] = SectorLearningPrivacyReasonCode::IncompatibleCurrency->value;
        }
        if (($candidate['mixed_attribution'] ?? false) === true) {
            $reasons[] = SectorLearningPrivacyReasonCode::IncompatibleAttribution->value;
        }

        if (($candidate['expose_min_max'] ?? false) === true) {
            $reasons[] = SectorLearningPrivacyReasonCode::UnsafeExactValue->value;
        }

        if (($candidate['small_cell'] ?? false) === true) {
            $reasons[] = SectorLearningPrivacyReasonCode::SmallCategoricalCell->value;
        }
        if (($candidate['complementary_disclosure_risk'] ?? false) === true) {
            $reasons[] = SectorLearningPrivacyReasonCode::ComplementaryDisclosureRisk->value;
        }

        if ($reasons !== []) {
            $disposition = SectorPrivacyDisposition::BlockedPrivacyNotQualified;
            if (in_array(SectorLearningPrivacyReasonCode::InsufficientDistinctBrands->value, $reasons, true)
                || in_array(SectorLearningPrivacyReasonCode::InsufficientDistinctCustomers->value, $reasons, true)
                || in_array(SectorLearningPrivacyReasonCode::InsufficientNumericCohort->value, $reasons, true)) {
                $disposition = SectorPrivacyDisposition::BlockedSmallCohort;
            }
            if (in_array(SectorLearningPrivacyReasonCode::DominantBrandContribution->value, $reasons, true)
                || in_array(SectorLearningPrivacyReasonCode::DominantCustomerContribution->value, $reasons, true)) {
                $disposition = SectorPrivacyDisposition::BlockedDominantContributor;
            }
            if (in_array(SectorLearningPrivacyReasonCode::UnsafeDimension->value, $reasons, true)
                || in_array(SectorLearningPrivacyReasonCode::IdentifyingDimension->value, $reasons, true)
                || in_array(SectorLearningPrivacyReasonCode::RareDimensionCombination->value, $reasons, true)) {
                $disposition = SectorPrivacyDisposition::BlockedIdentifyingDimension;
            }
            if (in_array(SectorLearningPrivacyReasonCode::IncompatibleMetric->value, $reasons, true)
                || in_array(SectorLearningPrivacyReasonCode::IncompatibleCurrency->value, $reasons, true)
                || in_array(SectorLearningPrivacyReasonCode::IncompatibleAttribution->value, $reasons, true)) {
                $disposition = SectorPrivacyDisposition::BlockedUnsupportedAggregation;
            }

            return $this->blocked($disposition, $reasons);
        }

        return new SectorPrivacyGateDecision(
            disposition: SectorPrivacyDisposition::Eligible,
            reasons: ['Privacy-qualified under '.self::POLICY_VERSION],
            policyVersion: self::POLICY_VERSION,
            safeMetadata: [
                'sector_code' => $sectorIdentity->code,
                'policy_version' => self::POLICY_VERSION,
                'documented_as' => 'product_disclosure_control_policy',
                'formal_k_anonymity_claim' => false,
                'differential_privacy_claim' => false,
            ],
        );
    }

    public function rejectSingleBrandAsSectorLearning(int $brandCount): SectorPrivacyGateDecision
    {
        return $this->blocked(
            SectorPrivacyDisposition::BlockedOneBrandInsufficient,
            [
                SectorLearningPrivacyReasonCode::OneBrandInsufficient->value,
                'One Brand cannot become usable Sector Memory.',
                "Observed contributing_brand_count={$brandCount}.",
            ],
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function blocked(SectorPrivacyDisposition $disposition, array $reasons): SectorPrivacyGateDecision
    {
        return new SectorPrivacyGateDecision(
            disposition: $disposition,
            reasons: $reasons,
            policyVersion: self::POLICY_VERSION,
            safeMetadata: [
                'policy_version' => self::POLICY_VERSION,
                'formal_k_anonymity_claim' => false,
                'differential_privacy_claim' => false,
            ],
        );
    }
}
