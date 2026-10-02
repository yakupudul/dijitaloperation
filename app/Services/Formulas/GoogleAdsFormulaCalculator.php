<?php

namespace App\Services\Formulas;

use App\Services\Formulas\Support\FormulaResult;

/**
 * Google Ads derived metrics per MOXDOP_FORMULA_REGISTRY_V1 (FORMULA_GADS_*).
 * Uses already-normalized cost_amount — never divides micros again.
 */
final class GoogleAdsFormulaCalculator
{
    public const string FORMULA_GADS_CTR = 'FORMULA_GADS_CTR';

    public const string FORMULA_GADS_CPC = 'FORMULA_GADS_CPC';

    public const string FORMULA_GADS_SPEND = 'FORMULA_GADS_SPEND';

    public const string FORMULA_GADS_CPA = 'FORMULA_GADS_CPA';

    public const string FORMULA_GADS_CVR = 'FORMULA_GADS_CVR';

    public const string FORMULA_PERIOD_RELATIVE_CHANGE = 'FORMULA_PERIOD_RELATIVE_CHANGE';

    public const string FORMULA_PERIOD_ABSOLUTE_DELTA = 'FORMULA_PERIOD_ABSOLUTE_DELTA';

    /**
     * @var list<string>
     */
    private const array REQUIRED_FORMULA_IDS = [
        self::FORMULA_GADS_CTR,
        self::FORMULA_GADS_CPC,
        self::FORMULA_GADS_SPEND,
        self::FORMULA_GADS_CPA,
        self::FORMULA_GADS_CVR,
        self::FORMULA_PERIOD_RELATIVE_CHANGE,
        self::FORMULA_PERIOD_ABSOLUTE_DELTA,
    ];

    public function __construct(
        private readonly FormulaRegistryLoader $registry,
    ) {}

    public function assertFormulasAvailable(): void
    {
        foreach (self::REQUIRED_FORMULA_IDS as $id) {
            $this->registry->formula($id);
        }
    }

    /**
     * sum(clicks) / sum(impressions). Never AVG(row CTR).
     */
    public function ctr(?int $clicks, ?int $impressions): FormulaResult
    {
        return $this->ratio(
            $clicks === null ? null : (float) $clicks,
            $impressions === null ? null : (float) $impressions,
        );
    }

    /**
     * sum(cost_amount) / sum(clicks). cost_amount is already normalized currency units.
     */
    public function cpc(?float $costAmount, ?int $clicks): FormulaResult
    {
        return $this->ratio($costAmount, $clicks === null ? null : (float) $clicks);
    }

    /**
     * Identity over already-normalized spend (cost_amount). Does NOT divide micros.
     */
    public function spend(?float $costAmount): FormulaResult
    {
        if ($costAmount === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        return FormulaResult::value($costAmount);
    }

    /**
     * sum(cost) / sum(typed_conversions). Denominator must be an explicit typed conversion
     * total — never generic "results". Not Qualified Lead / Business Outcome.
     */
    public function cpa(?float $costAmount, ?float $typedConversions): FormulaResult
    {
        return $this->ratio($costAmount, $typedConversions);
    }

    /**
     * sum(typed_conversions) / sum(clicks).
     */
    public function cvr(?float $typedConversions, ?int $clicks): FormulaResult
    {
        return $this->ratio($typedConversions, $clicks === null ? null : (float) $clicks);
    }

    public function periodRelativeChange(?float $current, ?float $previous): FormulaResult
    {
        if ($current === null || $previous === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        if ($previous == 0.0) {
            return $current == 0.0
                ? FormulaResult::state(FormulaResult::STATE_UNDEFINED)
                : FormulaResult::state(FormulaResult::STATE_UNDEFINED_RELATIVE_CHANGE);
        }

        return FormulaResult::value(($current - $previous) / $previous);
    }

    public function periodAbsoluteDelta(?float $current, ?float $previous): FormulaResult
    {
        if ($current === null || $previous === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        return FormulaResult::value($current - $previous);
    }

    private function ratio(?float $numerator, ?float $denominator): FormulaResult
    {
        if ($numerator === null || $denominator === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        if ($denominator == 0.0) {
            return $numerator == 0.0
                ? FormulaResult::state(FormulaResult::STATE_UNDEFINED)
                : FormulaResult::state(FormulaResult::STATE_UNDEFINED_ZERO_DENOMINATOR);
        }

        return FormulaResult::value($numerator / $denominator);
    }
}
