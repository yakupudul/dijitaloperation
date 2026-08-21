<?php

namespace App\Support\Demo;

use App\Support\Operator\OperatorPeriod;
use App\Support\Reality\DemoCatalogAssetGuard;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Reporting period resolution shared by operator workspaces.
 *
 * Demo catalog / fixture reads stay on {@see self::ANCHOR_DATE} so the 90-day
 * fixture series remains deterministic. Real provider/operator presets resolve
 * from wall-clock time unless an explicit anchor/timezone override is supplied
 * ({@see OperatorPeriod}). {@see DemoCatalogAssetGuard}
 * and fixture-context execution are the discriminators — not APP_ENV, because
 * browser Demo Mode can run under local/staging/production-like environments.
 */
final class DemoPeriod
{
    public const string TIMEZONE = 'Europe/Berlin';

    /** Deterministic fixture anchor used for Demo catalog / fixture coverage. */
    public const string ANCHOR_DATE = '2026-08-12';

    /** Demo catalog fixture series length (exclusive of the extra day in picker min). */
    public const int DEMO_HISTORY_DAYS = 89;

    /** Production GSC/GA4 custom-range ceiling (~16 months). */
    public const int PRODUCTION_HISTORY_DAYS = 486;

    private static int $fixtureAnchorDepth = 0;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function usingFixtureAnchor(callable $callback): mixed
    {
        self::$fixtureAnchorDepth++;

        try {
            return $callback();
        } finally {
            self::$fixtureAnchorDepth--;
        }
    }

    public static function inFixtureAnchorContext(): bool
    {
        return self::$fixtureAnchorDepth > 0;
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function bounds(
        string $preset,
        ?string $start = null,
        ?string $end = null,
        ?string $assetId = null,
        ?CarbonInterface $anchorOverride = null,
        ?string $timezone = null,
    ): array {
        [$anchor, $tz] = self::resolveAnchor($assetId, $anchorOverride, $timezone);

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
        ?string $assetId = null,
        ?CarbonInterface $anchorOverride = null,
        ?string $timezone = null,
    ): array {
        $current = self::bounds($preset, $start, $end, $assetId, $anchorOverride, $timezone);
        $days = $current['days'];
        $prevEnd = $current['start']->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        return self::pack('compare', $prevStart, $prevEnd);
    }

    /**
     * Same calendar period one year earlier while preserving the selected period's
     * inclusive day count across leap-day boundaries. Missing YoY coverage is a
     * caller concern (unavailable, not zero).
     *
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function yearOverYearBounds(
        string $preset,
        ?string $start = null,
        ?string $end = null,
        ?string $assetId = null,
        ?CarbonInterface $anchorOverride = null,
        ?string $timezone = null,
    ): array {
        $current = self::bounds($preset, $start, $end, $assetId, $anchorOverride, $timezone);
        $yoyStart = $current['start']->copy()->subYearNoOverflow();
        $yoyEnd = $yoyStart->copy()->addDays($current['days'] - 1);

        return self::pack('yoy', $yoyStart, $yoyEnd);
    }

    public static function anchor(?string $assetId = null): CarbonInterface
    {
        $configured = config('moxdop.reporting_anchor_date');
        if (is_string($configured) && trim($configured) !== '') {
            return Carbon::parse($configured, (string) config('app.timezone', self::TIMEZONE))->startOfDay();
        }

        if (self::usesFixtureAnchor($assetId)) {
            return Carbon::parse(self::ANCHOR_DATE, self::TIMEZONE)->startOfDay();
        }

        return Carbon::now((string) config('app.timezone', self::TIMEZONE))->startOfDay();
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
        ?string $assetId = null,
        ?CarbonInterface $anchorOverride = null,
        ?string $timezone = null,
    ): ?string {
        if (! filled($start) || ! filled($end)) {
            return __('operator.period.select_both');
        }

        [$anchor, $tz] = self::resolveAnchor($assetId, $anchorOverride, $timezone);
        $historyDays = self::historyDaysFor($assetId, $anchorOverride);

        try {
            $from = Carbon::parse($start, $tz)->startOfDay();
            $to = Carbon::parse($end, $tz)->startOfDay();
        } catch (\Throwable) {
            return __('operator.period.invalid_dates');
        }

        if ($from->greaterThan($to)) {
            return __('operator.period.start_before_end');
        }

        if ($from->greaterThan($anchor) || $to->greaterThan($anchor)) {
            return __('operator.period.after_available', ['date' => $anchor->format('M j, Y')]);
        }

        $earliest = $anchor->copy()->subDays($historyDays);
        if ($to->lessThan($earliest)) {
            return __('operator.period.no_data_range');
        }

        if ($from->diffInDays($to) + 1 > $historyDays + 1) {
            return $historyDays >= self::PRODUCTION_HISTORY_DAYS
                ? __('operator.period.max_16m')
                : __('operator.period.max_90');
        }

        return null;
    }

    public static function historyDaysFor(?string $assetId, ?CarbonInterface $anchorOverride = null): int
    {
        if ($anchorOverride !== null) {
            return self::PRODUCTION_HISTORY_DAYS;
        }

        $id = trim((string) $assetId);

        if ($id !== '' && DemoCatalogAssetGuard::isDemoCatalogAssetId($id)) {
            return self::DEMO_HISTORY_DAYS;
        }

        return self::PRODUCTION_HISTORY_DAYS;
    }

    /**
     * Day-span factors for aggregating Demo metrics (relative to a 28-day baseline).
     *
     * @return array{spend_factor: float, results_factor: float, efficiency_factor: float, label: string, narrative: string, days: int, start: string, end: string}
     */
    public static function factors(string $preset, ?string $start = null, ?string $end = null): array
    {
        if (! self::inFixtureAnchorContext()) {
            return self::usingFixtureAnchor(fn (): array => self::factors($preset, $start, $end));
        }

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

    /**
     * @return array{0: CarbonInterface, 1: string}
     */
    private static function resolveAnchor(
        ?string $assetId,
        ?CarbonInterface $anchorOverride,
        ?string $timezone,
    ): array {
        if ($anchorOverride !== null) {
            $tz = $timezone ?? self::TIMEZONE;
            $anchor = Carbon::parse($anchorOverride->toDateString(), $tz)->startOfDay();

            return [$anchor, $tz];
        }

        $anchor = self::anchor($assetId);
        $tz = $timezone ?? $anchor->timezoneName;

        return [$anchor, $tz];
    }

    private static function usesFixtureAnchor(?string $assetId): bool
    {
        if (self::inFixtureAnchorContext()) {
            return true;
        }

        if ($assetId === null || $assetId === '') {
            return false;
        }

        return DemoCatalogAssetGuard::isDemoCatalogAssetId($assetId);
    }
}
