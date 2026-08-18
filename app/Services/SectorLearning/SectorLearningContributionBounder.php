<?php

namespace App\Services\SectorLearning;

use App\Enums\SectorLearningPrivacyReasonCode;
use App\Support\SectorLearning\Dto\InternalSectorContribution;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;

/**
 * Brand- and Customer-balanced contribution bounding (Prompt 53).
 *
 * Raw Experience counts must not dominate aggregates.
 * Reduction: one effective unit per Brand within a cohort key (median presence).
 * Customer share capped by policy max effective share.
 */
final class SectorLearningContributionBounder
{
    public const string VERSION = 'sector_bounding_v1';

    /**
     * @param  list<InternalSectorContribution>  $contributions
     * @return array{
     *     ok: bool,
     *     contributions: list<InternalSectorContribution>,
     *     distinct_brands: int,
     *     distinct_customers: int,
     *     brand_shares: array<int, float>,
     *     customer_shares: array<int, float>,
     *     reasons: list<string>
     * }
     */
    public function bound(array $contributions): array
    {
        if ($contributions === []) {
            return [
                'ok' => false,
                'contributions' => [],
                'distinct_brands' => 0,
                'distinct_customers' => 0,
                'brand_shares' => [],
                'customer_shares' => [],
                'reasons' => [SectorLearningPrivacyReasonCode::ContributionNotQualified->value],
            ];
        }

        /** @var array<int, list<InternalSectorContribution>> $byBrand */
        $byBrand = [];
        foreach ($contributions as $contribution) {
            $byBrand[$contribution->brandId][] = $contribution;
        }

        $brandUnits = [];
        foreach ($byBrand as $brandId => $rows) {
            // Deterministic Brand reduction: pick median-indexed contribution by fingerprint (not best/favorable).
            usort(
                $rows,
                static fn (InternalSectorContribution $a, InternalSectorContribution $b): int => strcmp(
                    $a->projection->contributionFingerprint,
                    $b->projection->contributionFingerprint
                )
            );
            $index = (int) floor((count($rows) - 1) / 2);
            $chosen = $rows[$index];
            $brandUnits[$brandId] = new InternalSectorContribution(
                projection: $chosen->projection,
                brandExperienceId: $chosen->brandExperienceId,
                brandExperienceRevisionId: $chosen->brandExperienceRevisionId,
                brandId: $chosen->brandId,
                customerId: $chosen->customerId,
                effectiveWeight: 1.0,
            );
        }

        $brandCount = count($brandUnits);

        /** @var array<int, list<InternalSectorContribution>> $byCustomer */
        $byCustomer = [];
        foreach ($brandUnits as $unit) {
            $byCustomer[$unit->customerId][] = $unit;
        }

        $customerCount = count($byCustomer);
        $reasons = [];
        $bounded = [];

        foreach ($byCustomer as $customerId => $units) {
            $rawShare = count($units) / max(1, $brandCount);
            if ($rawShare > SectorLearningPrivacyPolicy::MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE + 1e-9) {
                $otherBrandCount = $brandCount - count($units);
                $maxShare = SectorLearningPrivacyPolicy::MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE;
                if ($otherBrandCount <= 0 || $maxShare >= 1.0) {
                    // Cannot rebalance a single-customer cohort — gate will block.
                    foreach ($units as $unit) {
                        $bounded[] = new InternalSectorContribution(
                            projection: $unit->projection,
                            brandExperienceId: $unit->brandExperienceId,
                            brandExperienceRevisionId: $unit->brandExperienceRevisionId,
                            brandId: $unit->brandId,
                            customerId: $unit->customerId,
                            effectiveWeight: 1.0,
                        );
                    }
                } else {
                    // Solve: n*w / (n*w + other) = maxShare  ⇒  w = (maxShare * other) / (n * (1 - maxShare))
                    $n = count($units);
                    $weight = ($maxShare * $otherBrandCount) / ($n * (1.0 - $maxShare));
                    foreach ($units as $unit) {
                        $bounded[] = new InternalSectorContribution(
                            projection: $unit->projection,
                            brandExperienceId: $unit->brandExperienceId,
                            brandExperienceRevisionId: $unit->brandExperienceRevisionId,
                            brandId: $unit->brandId,
                            customerId: $unit->customerId,
                            effectiveWeight: $weight,
                        );
                    }
                }
            } else {
                foreach ($units as $unit) {
                    $bounded[] = new InternalSectorContribution(
                        projection: $unit->projection,
                        brandExperienceId: $unit->brandExperienceId,
                        brandExperienceRevisionId: $unit->brandExperienceRevisionId,
                        brandId: $unit->brandId,
                        customerId: $unit->customerId,
                        effectiveWeight: 1.0,
                    );
                }
            }
        }

        // Recalculate shares from effective weights for gate checks.
        $totalWeight = array_sum(array_map(
            static fn (InternalSectorContribution $c): float => $c->effectiveWeight,
            $bounded
        ));
        $brandShares = [];
        $customerShares = [];
        foreach ($bounded as $unit) {
            $w = $totalWeight > 0 ? $unit->effectiveWeight / $totalWeight : 0.0;
            $brandShares[$unit->brandId] = ($brandShares[$unit->brandId] ?? 0.0) + $w;
            $customerShares[$unit->customerId] = ($customerShares[$unit->customerId] ?? 0.0) + $w;
        }

        $ok = true;
        foreach ($brandShares as $share) {
            if ($share > SectorLearningPrivacyPolicy::MAX_SINGLE_BRAND_EFFECTIVE_SHARE + 1e-9) {
                $ok = false;
                $reasons[] = SectorLearningPrivacyReasonCode::DominantBrandContribution->value;
            }
        }
        foreach ($customerShares as $share) {
            if ($share > SectorLearningPrivacyPolicy::MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE + 1e-9) {
                $ok = false;
                $reasons[] = SectorLearningPrivacyReasonCode::DominantCustomerContribution->value;
            }
        }

        return [
            'ok' => $ok,
            'contributions' => $bounded,
            'distinct_brands' => $brandCount,
            'distinct_customers' => $customerCount,
            'brand_shares' => $brandShares,
            'customer_shares' => $customerShares,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
}
