<?php

namespace App\Services\GoogleAds\Support;

use Carbon\CarbonImmutable;

/** Locale-aware presentation helpers shared by the Google Ads read services and views. */
final class GoogleAdsDisplayFormat
{
    /** Signed period-over-period change, e.g. "önceki döneme göre +%12,5" / "+12.5% vs previous period". */
    public static function periodDelta(?float $percent): string
    {
        if ($percent === null || ! is_finite($percent)) {
            return (string) __('operator_gads.delta.unavailable');
        }

        $isTr = app()->getLocale() === 'tr';
        $sign = $percent >= 0 ? '+' : '-';
        $digits = number_format(abs($percent), 1, $isTr ? ',' : '.', $isTr ? '.' : ',');
        $formatted = $isTr ? $sign.'%'.$digits : $sign.$digits.'%';

        return (string) __('operator_gads.delta.vs_previous', ['delta' => $formatted]);
    }

    /** Relative change in percent, or null when the previous value cannot anchor a comparison. */
    public static function percentChange(float $current, ?float $previous): ?float
    {
        if ($previous === null || abs($previous) < 0.000001) {
            return null;
        }

        return ($current - $previous) / abs($previous) * 100;
    }

    /** Short chart axis label in the application locale ("5 Tem" / "Jul 5"). */
    public static function chartDate(string $date): string
    {
        return CarbonImmutable::parse($date)
            ->locale(app()->getLocale())
            ->translatedFormat((string) __('operator_gads.chart_date_format'));
    }

    /** Operator-facing campaign / entity status label. */
    public static function status(?string $status): string
    {
        return match (strtoupper(trim((string) $status))) {
            'ENABLED' => (string) __('operator_gads.status.enabled'),
            'PAUSED' => (string) __('operator_gads.status.paused'),
            'REMOVED' => (string) __('operator_gads.status.removed'),
            '', 'UNKNOWN', 'UNSPECIFIED' => (string) __('operator_gads.status.unknown'),
            default => ucfirst(strtolower(str_replace('_', ' ', (string) $status))),
        };
    }
}
