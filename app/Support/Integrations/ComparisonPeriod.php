<?php

namespace App\Support\Integrations;

use Carbon\CarbonImmutable;

/**
 * Bounded comparison windows for provider collection.
 *
 * Default diagnosis window is the last 28 complete days vs the preceding 28
 * days. Operator-selected presets (last 7/14/30 days, this/last month, custom)
 * build a "current" window plus an equal-length immediately-preceding
 * "previous" window for prior-period comparison. Missing ≠ zero: a comparison
 * window is only offered, never a fabricated delta.
 */
final class ComparisonPeriod
{
    public const string PRESET_LAST_7 = 'last7';

    public const string PRESET_LAST_14 = 'last14';

    public const string PRESET_LAST_28 = 'last28';

    public const string PRESET_LAST_30 = 'last30';

    public const string PRESET_THIS_MONTH = 'thisMonth';

    public const string PRESET_LAST_MONTH = 'lastMonth';

    public const string PRESET_CUSTOM = 'custom';

    /**
     * Operator-facing preset labels. `custom` renders its own date range.
     *
     * @return array<string, string>
     */
    public static function presetLabels(): array
    {
        return [
            self::PRESET_LAST_7 => 'Last 7 days',
            self::PRESET_LAST_14 => 'Last 14 days',
            self::PRESET_LAST_28 => 'Last 28 days',
            self::PRESET_LAST_30 => 'Last 30 days',
            self::PRESET_THIS_MONTH => 'This month',
            self::PRESET_LAST_MONTH => 'Last month',
            self::PRESET_CUSTOM => 'Custom',
        ];
    }

    /**
     * @return list<string>
     */
    public static function presetKeys(): array
    {
        return array_keys(self::presetLabels());
    }

    public static function isPreset(?string $preset): bool
    {
        return $preset !== null && in_array($preset, self::presetKeys(), true);
    }

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
            'preset' => self::PRESET_LAST_28,
            'compare' => true,
            'label' => self::presetLabels()[self::PRESET_LAST_28],
        ];
    }

    /**
     * Resolve an operator-selected period into current + equal-length previous
     * windows. Unknown presets fall back to the default 28-day window.
     *
     * @return array{
     *     current: array{start: string, end: string},
     *     previous: array{start: string, end: string},
     *     timezone: string,
     *     complete_days: int,
     *     preset: string,
     *     compare: bool,
     *     label: string
     * }
     */
    public static function forPreset(
        string $preset,
        ?string $customStart = null,
        ?string $customEnd = null,
        bool $compare = true,
        ?CarbonImmutable $now = null,
    ): array {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfDay();
        $lastCompleteDay = $now->subDay();

        [$currentStart, $currentEnd] = match ($preset) {
            self::PRESET_LAST_7 => [$lastCompleteDay->subDays(6), $lastCompleteDay],
            self::PRESET_LAST_14 => [$lastCompleteDay->subDays(13), $lastCompleteDay],
            self::PRESET_LAST_30 => [$lastCompleteDay->subDays(29), $lastCompleteDay],
            self::PRESET_THIS_MONTH => [$now->startOfMonth(), $lastCompleteDay->greaterThanOrEqualTo($now->startOfMonth()) ? $lastCompleteDay : $now->startOfMonth()],
            self::PRESET_LAST_MONTH => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            self::PRESET_CUSTOM => self::resolveCustom($customStart, $customEnd, $lastCompleteDay),
            default => [$lastCompleteDay->subDays(27), $lastCompleteDay],
        };

        if ($currentEnd->lessThan($currentStart)) {
            $currentEnd = $currentStart;
        }

        $days = (int) $currentStart->diffInDays($currentEnd) + 1;
        $previousEnd = $currentStart->subDay();
        $previousStart = $previousEnd->subDays($days - 1);

        $resolvedPreset = self::isPreset($preset) ? $preset : self::PRESET_LAST_28;

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
            'complete_days' => (int) $days,
            'preset' => $resolvedPreset,
            'compare' => $compare,
            'label' => self::presetLabels()[$resolvedPreset] ?? 'Last 28 days',
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function resolveCustom(?string $customStart, ?string $customEnd, CarbonImmutable $lastCompleteDay): array
    {
        $start = self::safeDate($customStart);
        $end = self::safeDate($customEnd);

        if ($start === null || $end === null) {
            // Incomplete custom range → default to last 28 complete days.
            return [$lastCompleteDay->subDays(27), $lastCompleteDay];
        }

        if ($end->greaterThan($lastCompleteDay)) {
            $end = $lastCompleteDay; // never request incomplete/future days
        }

        if ($start->greaterThan($end)) {
            $start = $end;
        }

        return [$start, $end];
    }

    private static function safeDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', trim($value), 'UTC') ?: null;
        } catch (\Throwable) {
            return null;
        }
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
