<?php

namespace App\Services\Advisor\Anomaly;

/**
 * Faz 14 — noise-resistant anomaly maths (pure functions): median, MAD, robust z-score (a single odd day does
 * not move the baseline the way a mean does) and an exponentially weighted moving average for sustained drift.
 */
final class RobustAnomaly
{
    /** @param  list<float|int>  $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    /** Median absolute deviation. @param  list<float|int>  $values */
    public static function mad(array $values): float
    {
        $median = self::median($values);

        return self::median(array_map(static fn ($v): float => abs((float) $v - $median), $values));
    }

    /**
     * Robust z-score of $value against $baseline (1.4826 × MAD ≈ σ for normal data). When MAD is 0 a small floor
     * (5 % of the median, at least 1) avoids dividing by zero on flat series.
     *
     * @param  list<float|int>  $baseline
     */
    public static function zScore(float $value, array $baseline): float
    {
        $median = self::median($baseline);
        $scale = 1.4826 * self::mad($baseline);
        $scale = $scale > 0 ? $scale : max(1.0, abs($median) * 0.05);

        return ($value - $median) / $scale;
    }

    /** @param  list<float|int>  $values oldest first */
    public static function ewma(array $values, float $alpha = 0.3): float
    {
        $ewma = null;
        foreach ($values as $value) {
            $ewma = $ewma === null ? (float) $value : $alpha * (float) $value + (1 - $alpha) * $ewma;
        }

        return (float) ($ewma ?? 0.0);
    }
}
