<?php

namespace App\Services\Formulas;

use App\Services\Formulas\Support\FormulaResult;

/**
 * GSC derived metrics per MOXDOP_FORMULA_REGISTRY_V1 (FORMULA_GSC_*).
 */
final class GscFormulaCalculator
{
    public const string FORMULA_GSC_CTR = 'FORMULA_GSC_CTR';

    public const string FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION = 'FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION';

    public const string FORMULA_PERIOD_RELATIVE_CHANGE = 'FORMULA_PERIOD_RELATIVE_CHANGE';

    public const string FORMULA_PERIOD_ABSOLUTE_DELTA = 'FORMULA_PERIOD_ABSOLUTE_DELTA';

    /**
     * @var list<string>
     */
    private const array REQUIRED_FORMULA_IDS = [
        self::FORMULA_GSC_CTR,
        self::FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION,
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
     * sum(clicks) / sum(impressions). FORMULA_GSC_CTR.
     */
    public function ctr(?int $clicks, ?int $impressions): FormulaResult
    {
        return $this->ratio(
            $clicks === null ? null : (float) $clicks,
            $impressions === null ? null : (float) $impressions,
        );
    }

    /**
     * sum(position * impressions) / sum(impressions). FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION.
     */
    public function impressionWeightedPosition(?float $positionTimesImpressions, ?int $impressions): FormulaResult
    {
        return $this->ratio(
            $positionTimesImpressions,
            $impressions === null ? null : (float) $impressions,
        );
    }

    /**
     * (current - previous) / previous. FORMULA_PERIOD_RELATIVE_CHANGE.
     */
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

    /**
     * current - previous. FORMULA_PERIOD_ABSOLUTE_DELTA.
     */
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
