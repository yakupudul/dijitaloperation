<?php

namespace App\Services\Collection\Providers\GoogleAds;

use InvalidArgumentException;

/**
 * Fixed read-only GAQL for low-volume lifetime activity discovery.
 *
 * Monthly segmentation is intentional: Google Ads limits granular date/week/hour
 * lookback, while month/quarter/year segmentation is the supported path for older
 * historical reporting.
 */
final class GoogleAdsHistoricalActivityGaqlBuilder
{
    public function monthly(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(<<<'GAQL'
SELECT
  segments.month,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value
FROM customer
WHERE segments.date BETWEEN '%s' AND '%s'
ORDER BY segments.month ASC
GAQL, $start, $end);
    }

    private function assertDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Google Ads history date must be Y-m-d.');
        }
    }
}
