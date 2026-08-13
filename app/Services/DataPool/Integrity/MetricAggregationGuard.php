<?php

namespace App\Services\DataPool\Integrity;

use InvalidArgumentException;

/**
 * Prevents invalid provider-total reconciliation via lower-grain summation.
 */
final class MetricAggregationGuard
{
    /**
     * @param  list<string>  $forbidSumMetrics
     */
    public function assertSummationAllowed(string $metric, array $forbidSumMetrics, string $dimension = 'date'): void
    {
        $normalized = $this->normalize($metric);
        foreach ($forbidSumMetrics as $forbidden) {
            if ($this->normalize((string) $forbidden) === $normalized) {
                throw new InvalidArgumentException(
                    "Metric [{$metric}] is non-additive across {$dimension}; summation reconciliation is forbidden."
                );
            }
        }

        if ($this->isKnownNonAdditive($normalized)) {
            throw new InvalidArgumentException(
                "Metric [{$metric}] is non-additive across {$dimension}; summation reconciliation is forbidden."
            );
        }
    }

    public function canSumAcrossDates(string $metric, array $forbidSumMetrics = []): bool
    {
        try {
            $this->assertSummationAllowed($metric, $forbidSumMetrics, 'date');

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function canAverageBlindly(string $metric): bool
    {
        $normalized = $this->normalize($metric);

        // Frequency and similar ratios must not be blindly averaged for period totals.
        return ! in_array($normalized, ['frequency', 'ctr', 'cpc', 'cpa', 'position'], true);
    }

    private function normalize(string $metric): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($metric)));
    }

    private function isKnownNonAdditive(string $normalized): bool
    {
        return in_array($normalized, [
            'reach',
            'frequency',
            'totalusers',
            'total_users',
            'activeusers',
            'active_users',
            'newusers',
            'new_users',
            'users',
            'unique_users',
            'position',
        ], true);
    }
}
