<?php

namespace MoxDop\MetaAds\Support;

/**
 * Canonical Meta percentage semantics.
 *
 * Meta Insights fields such as `ctr`, `inline_link_click_ctr`, and
 * `outbound_clicks_ctr` are stored as **percentage points**:
 *
 * - `1.48` means **1.48%**
 * - `0.012` would mean **0.012%** (almost never what Meta returns)
 *
 * Do NOT multiply by 100 again at presentation or AI-context formatting.
 * Google Ads stores CTR as a ratio (0.0148) and multiplies by 100 in UI —
 * that Google pattern must not be applied to Meta.
 */
final class MetaPercentage
{
    /**
     * Format a Meta percentage-point value for operator display.
     */
    public static function format(mixed $value, int $decimals = 2): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, $decimals).'%';
    }

    /**
     * Whether the given workspace KPI/metric key is a Meta percentage-point field.
     */
    public static function isPercentagePointKey(string $key): bool
    {
        return in_array($key, [
            'ctr',
            'inline_link_click_ctr',
            'outbound_clicks_ctr',
        ], true);
    }
}
