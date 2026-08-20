<?php

namespace App\Support\Demo;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Canonical Demo Mode reporting period resolution.
 * Uses a coherent account timezone and deterministic anchor ("data through" date).
 */
final class DemoPeriod
{
    public const string TIMEZONE = 'Europe/Berlin';

    /** Deterministic "today" for Demo fixtures (data through Aug 12, 2026). */
    public const string ANCHOR_DATE = '2026-08-12';

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function bounds(
        string $preset,
        ?string $start = null,
        ?string $end = null,
        ?CarbonInterface $anchorOverride = null,
        ?string $timezone = null,
    ): array {
        $tz = $timezone ?? self::TIMEZONE;
        $anchor = $anchorOverride !== null
            ? Carbon::parse($anchorOverride->toDateString(), $tz)->startOfDay()
            : Carbon::parse(self::ANCHOR_DATE, $tz)->startOfDay();

        if ($preset === 'custom' && filled($start) && filled($end)) {
            $from = Carbon::parse($start, $tz)->startOfDay();
            $to = Carbon::parse($end, $tz)->startOfDay();
            if ($from->greaterThan($to)) {
                [$from, $to] = [$to, $from];
            }

            return self::pack($preset, $from, $to);
        }

        return match ($preset) {
            'last_7' => self::pack($preset, $anchor->copy()->subDays(6), $anchor->copy()),
            'last_14' => self::pack($preset, $anchor->copy()->subDays(13), $anchor->copy()),
            'last_28' => self::pack($preset, $anchor->copy()->subDays(27), $anchor->copy()),
            'last_30' => self::pack($preset, $anchor->copy()->subDays(29), $anchor->copy()),
            'last_90' => self::pack($preset, $anchor->copy()->subDays(89), $anchor->copy()),
            'this_month' => self::pack($preset, $anchor->copy()->startOfMonth(), $anchor->copy()),
            'last_month' => self::pack(
                $preset,
                $anchor->copy()->subMonthNoOverflow()->startOfMonth(),
                $anchor->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay(),
            ),
            'custom' => self::pack(
                $preset,
                $anchor->copy()->subDays(21),
                $anchor->copy()->subDays(2),
            ),
            default => self::pack('last_28', $anchor->copy()->subDays(27), $anchor->copy()),
        };
    }

    /**
     * Previous period of equal calendar length immediately preceding the selected range.
     *
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function previousBounds(
        string $preset,
        ?string $start = null,
        ?string $end = null,
        ?CarbonInterface $anchorOverride = null,
        ?string $timezone = null,
    ): array {
        $current = self::bounds($preset, $start, $end, $anchorOverride, $timezone);
        $days = $current['days'];
        $prevEnd = $current['start']->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        return self::pack('compare', $prevStart, $prevEnd);
    }

    public static function anchor(): CarbonInterface
    {
        return Carbon::parse(self::ANCHOR_DATE, self::TIMEZONE)->startOfDay();
    }

    public static function formatRangeLabel(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($start->isSameMonth($end) && $start->isSameYear($end)) {
            return $start->format('M j').' – '.$end->format('j');
        }

        if ($start->isSameYear($end)) {
            return $start->format('M j').' – '.$end->format('M j');
        }

        return $start->format('M j, Y').' – '.$end->format('M j, Y');
    }

    /**
     * Validate a custom range. Returns null when valid, otherwise an error message.
     */
    public static function validateCustom(
        ?string $start,
        ?string $end,
        ?CarbonInterface $anchorOverride = null,
        ?string $timezone = null,
    ): ?string {
        if (! filled($start) || ! filled($end)) {
            return __('operator.period.select_both');
        }

        $tz = $timezone ?? self::TIMEZONE;

        try {
            $from = Carbon::parse($start, $tz)->startOfDay();
            $to = Carbon::parse($end, $tz)->startOfDay();
        } catch (\Throwable) {
            return __('operator.period.invalid_dates');
        }

        if ($from->greaterThan($to)) {
            return __('operator.period.start_before_end');
        }

        $anchor = $anchorOverride !== null
            ? Carbon::parse($anchorOverride->toDateString(), $tz)->startOfDay()
            : self::anchor();
        if ($from->greaterThan($anchor) || $to->greaterThan($anchor)) {
            return __('operator.period.after_available', ['date' => $anchor->format('M j, Y')]);
        }

        $earliest = $anchor->copy()->subDays(89);
        if ($to->lessThan($earliest)) {
            return __('operator.period.no_data_range');
        }

        if ($from->diffInDays($to) + 1 > 90) {
            return __('operator.period.max_90');
        }

        return null;
    }

    /**
     * Day-span factors for aggregating Demo metrics (relative to a 28-day baseline).
     *
     * @return array{spend_factor: float, results_factor: float, efficiency_factor: float, label: string, narrative: string, days: int, start: string, end: string}
     */
    public static function factors(string $preset, ?string $start = null, ?string $end = null): array
    {
        if ($preset === 'custom') {
            $start = $start ?: (DemoState::all()['period_start'] ?? null);
            $end = $end ?: (DemoState::all()['period_end'] ?? null);
        }

        $bounds = self::bounds($preset, $start, $end);
        $days = $bounds['days'];
        $baseline = 28.0;
        $ratio = max(0.05, min(3.0, $days / $baseline));

        $presetFactors = match ($preset) {
            'last_7' => ['spend' => 0.22, 'results' => 0.18, 'efficiency' => 1.18, 'narrative' => 'Short window — CPL elevated after weekend auction pressure.'],
            'last_14' => ['spend' => 0.48, 'results' => 0.42, 'efficiency' => 1.12, 'narrative' => 'Creative fatigue visible; Meta CPL still above May baseline.'],
            'last_28' => ['spend' => 1.00, 'results' => 1.00, 'efficiency' => 1.00, 'narrative' => 'July recovery underway after May deterioration; Meta still the efficiency bottleneck.'],
            'last_30' => ['spend' => 1.08, 'results' => 1.02, 'efficiency' => 1.04, 'narrative' => 'Slightly longer window softens daily volatility; waste share still material.'],
            'last_90' => ['spend' => 2.85, 'results' => 2.70, 'efficiency' => 1.06, 'narrative' => 'Quarter-scale window — seasonal demand and recovery patterns visible.'],
            'this_month' => ['spend' => 0.55, 'results' => 0.58, 'efficiency' => 0.94, 'narrative' => 'Month-to-date recovery: results improving faster than spend.'],
            'last_month' => ['spend' => 1.22, 'results' => 0.92, 'efficiency' => 1.28, 'narrative' => 'May-style deterioration window — spend up, leads down, CPL peaked.'],
            'custom' => [
                'spend' => round($ratio * (0.92 + (($days % 5) * 0.01)), 4),
                'results' => round($ratio * (0.88 + (($days % 7) * 0.012)), 4),
                'efficiency' => round(1.0 + ((14 - min(28, $days)) * 0.008), 4),
                'narrative' => 'Custom range aggregated from daily Demo fixtures.',
            ],
            default => ['spend' => 1.00, 'results' => 1.00, 'efficiency' => 1.00, 'narrative' => 'July recovery underway after May deterioration; Meta still the efficiency bottleneck.'],
        };

        return [
            'spend_factor' => (float) $presetFactors['spend'],
            'results_factor' => (float) $presetFactors['results'],
            'efficiency_factor' => (float) $presetFactors['efficiency'],
            'label' => $bounds['label'],
            'narrative' => (string) $presetFactors['narrative'],
            'days' => $days,
            'start' => $bounds['start']->toDateString(),
            'end' => $bounds['end']->toDateString(),
        ];
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    private static function pack(string $preset, CarbonInterface $start, CarbonInterface $end): array
    {
        $from = $start->copy()->startOfDay();
        $to = $end->copy()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;

        return [
            'start' => $from,
            'end' => $to,
            'days' => max(1, $days),
            'label' => self::formatRangeLabel($from, $to),
            'preset' => $preset,
        ];
    }
}
