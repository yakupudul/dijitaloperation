<?php

namespace App\Support\Integrations;

use Carbon\CarbonImmutable;

/**
 * Default diagnosis comparison window: last 28 complete days vs preceding 28 days.
 */
final class ComparisonPeriod
{
    /**
     * @return array{
     *     current: array{start: string, end: string},
     *     previous: array{start: string, end: string},
     *     timezone: string,
     *     complete_days: int
     * }
     */
    public static function lastTwentyEightCompleteDays(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfDay();
        $currentEnd = $now->subDay(); // yesterday = last complete day
        $currentStart = $currentEnd->subDays(27);
        $previousEnd = $currentStart->subDay();
        $previousStart = $previousEnd->subDays(27);

        return [
            'current' => [
                'start' => $currentStart->toDateString(),
                'end' => $currentEnd->toDateString(),
            ],
            'previous' => [
                'start' => $previousStart->toDateString(),
                'end' => $previousEnd->toDateString(),
            ],
            'timezone' => 'UTC',
            'complete_days' => 28,
        ];
    }

    public static function percentDelta(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        $previous = (float) $previous;
        if (abs($previous) < 0.0000001) {
            return null;
        }

        return round((((float) $current) - $previous) / $previous * 100, 2);
    }

    public static function absoluteDelta(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return round(((float) $current) - ((float) $previous), 4);
    }
}
