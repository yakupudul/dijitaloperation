<?php

namespace App\Services\Collection\Providers\Ga4;

use InvalidArgumentException;

/**
 * Contract-driven GA4 Core Reporting request builder — no ad-hoc UI composition.
 * Central ingestion may use explicitly catalogued aggregate acquisition dimensions;
 * user/client identifiers and arbitrary custom dimensions remain forbidden.
 */
final class Ga4ReportRequestBuilder
{
    /**
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @return array<string, mixed>
     */
    public function build(
        array $dimensions,
        array $metrics,
        string $startDate,
        string $endDate,
        int $offset,
        int $limit,
        bool $keepEmptyRows,
        bool $returnPropertyQuota,
    ): array {
        $this->assertNoForbiddenDimensions($dimensions);
        $this->assertNoForbiddenMetrics($metrics);

        $body = [
            'dateRanges' => [[
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]],
            'dimensions' => array_map(static fn (string $name): array => ['name' => $name], $dimensions),
            'metrics' => array_map(static fn (string $name): array => ['name' => $name], $metrics),
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'keepEmptyRows' => $keepEmptyRows,
            'returnPropertyQuota' => $returnPropertyQuota,
        ];

        return $body;
    }

    /**
     * @param  list<string>  $dimensions
     */
    private function assertNoForbiddenDimensions(array $dimensions): void
    {
        $forbidden = [
            'userId',
            'clientId',
            'userPseudoId',
            'landingPagePlusQueryString',
        ];
        foreach ($dimensions as $dimension) {
            if (in_array($dimension, $forbidden, true)) {
                throw new InvalidArgumentException('CONTRACT_MISMATCH: forbidden GA4 dimension '.$dimension);
            }
            if (str_starts_with($dimension, 'customEvent:') || str_starts_with($dimension, 'customUser:')) {
                throw new InvalidArgumentException('CONTRACT_MISMATCH: arbitrary custom dimension not allowed');
            }
        }
    }

    /**
     * @param  list<string>  $metrics
     */
    private function assertNoForbiddenMetrics(array $metrics): void
    {
        foreach ($metrics as $metric) {
            if (str_starts_with($metric, 'customEvent:') || str_contains($metric, 'purchaseRevenue')) {
                throw new InvalidArgumentException('CONTRACT_MISMATCH: non-contract GA4 metric '.$metric);
            }
        }
    }
}
