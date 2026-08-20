<?php

namespace App\Support\Operator;

use App\Support\Demo\DemoPeriod;
use Carbon\CarbonInterface;

/**
 * Shared period resolution for specialist read services.
 * When the operator UI supplies explicit from/to dates, those dates win so
 * presets and custom ranges actually change the underlying warehouse queries.
 * Service-level calls that omit dates keep DemoPeriod preset math (existing tests).
 */
final class OperatorReportingPeriod
{
    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function queryBounds(string $preset, ?string $start = null, ?string $end = null): array
    {
        if (filled($start) && filled($end)) {
            return DemoPeriod::bounds('custom', $start, $end);
        }

        return DemoPeriod::bounds($preset, $start, $end);
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function previousQueryBounds(string $preset, ?string $start = null, ?string $end = null): array
    {
        $current = self::queryBounds($preset, $start, $end);

        return DemoPeriod::previousBounds(
            'custom',
            $current['start']->toDateString(),
            $current['end']->toDateString(),
        );
    }
}
