<?php

namespace App\Support\Operator;

use App\Support\Demo\DemoPeriod;
use Carbon\CarbonInterface;

/**
 * Production reporting period resolution using the agency OperatorClock.
 * Demo catalog workspaces keep {@see DemoPeriod} and its frozen anchor.
 */
final class OperatorPeriod
{
    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function bounds(string $preset, ?string $start = null, ?string $end = null): array
    {
        return DemoPeriod::bounds($preset, $start, $end, self::anchor(), self::timezone());
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface, days: int, label: string, preset: string}
     */
    public static function previousBounds(string $preset, ?string $start = null, ?string $end = null): array
    {
        return DemoPeriod::previousBounds($preset, $start, $end, self::anchor(), self::timezone());
    }

    public static function validateCustom(?string $start, ?string $end): ?string
    {
        return DemoPeriod::validateCustom($start, $end, self::anchor(), self::timezone());
    }

    public static function anchor(): CarbonInterface
    {
        return OperatorClock::now()->startOfDay();
    }

    public static function timezone(): string
    {
        return OperatorClock::timezone();
    }

    public static function pickerMaxDate(): string
    {
        return self::anchor()->toDateString();
    }

    public static function pickerMinDate(): string
    {
        return self::anchor()->copy()->subDays(89)->toDateString();
    }
}
