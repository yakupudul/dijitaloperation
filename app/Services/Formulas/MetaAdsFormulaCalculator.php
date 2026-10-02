<?php

namespace App\Services\Formulas;

use App\Services\Formulas\Support\FormulaResult;

/**
 * Meta Ads derived metrics per MOXDOP_FORMULA_REGISTRY_V1 (FORMULA_META_*).
 * Spend is already major currency units — never treat as Google Ads micros.
 */
final class MetaAdsFormulaCalculator
{
    public const string FORMULA_META_CTR_ALL = 'FORMULA_META_CTR_ALL';

    public const string FORMULA_META_LINK_CTR = 'FORMULA_META_LINK_CTR';

    public const string FORMULA_META_CPC = 'FORMULA_META_CPC';

    public const string FORMULA_META_COST_PER_LINK_CLICK = 'FORMULA_META_COST_PER_LINK_CLICK';

    public const string FORMULA_META_CPM = 'FORMULA_META_CPM';

    public const string FORMULA_META_SPEND = 'FORMULA_META_SPEND';

    public const string FORMULA_PERIOD_RELATIVE_CHANGE = 'FORMULA_PERIOD_RELATIVE_CHANGE';

    /**
     * @var list<string>
     */
    private const array REQUIRED_FORMULA_IDS = [
        self::FORMULA_META_CTR_ALL,
        self::FORMULA_META_LINK_CTR,
        self::FORMULA_META_CPC,
        self::FORMULA_META_COST_PER_LINK_CLICK,
        self::FORMULA_META_CPM,
        self::FORMULA_META_SPEND,
        self::FORMULA_PERIOD_RELATIVE_CHANGE,
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

    public function ctrAll(?int $clicks, ?int $impressions): FormulaResult
    {
        return $this->ratio(
            $clicks === null ? null : (float) $clicks,
            $impressions === null ? null : (float) $impressions,
        );
    }

    public function linkCtr(?int $linkClicks, ?int $impressions): FormulaResult
    {
        return $this->ratio(
            $linkClicks === null ? null : (float) $linkClicks,
            $impressions === null ? null : (float) $impressions,
        );
    }

    public function cpc(?float $spend, ?int $clicks): FormulaResult
    {
        return $this->ratio($spend, $clicks === null ? null : (float) $clicks);
    }

    public function costPerLinkClick(?float $spend, ?int $linkClicks): FormulaResult
    {
        return $this->ratio($spend, $linkClicks === null ? null : (float) $linkClicks);
    }

    public function cpm(?float $spend, ?int $impressions): FormulaResult
    {
        if ($spend === null || $impressions === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        if ($impressions == 0) {
            return $spend == 0.0
                ? FormulaResult::state(FormulaResult::STATE_UNDEFINED)
                : FormulaResult::state(FormulaResult::STATE_UNDEFINED_ZERO_DENOMINATOR);
        }

        return FormulaResult::value(($spend / $impressions) * 1000.0);
    }

    public function spend(?float $spend): FormulaResult
    {
        if ($spend === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        return FormulaResult::value($spend);
    }

    /**
     * Typed cost-per-action. Denominator must be an explicit typed action count —
     * never generic Results.
     */
    public function costPerTypedAction(?float $spend, ?float $typedActionCount): FormulaResult
    {
        return $this->ratio($spend, $typedActionCount);
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
