<?php

namespace App\Services\Formulas;

use App\Services\Formulas\Support\FormulaResult;

/**
 * GA4 derived metrics per MOXDOP_FORMULA_REGISTRY_V1 (FORMULA_GA4_*).
 * Providers supply facts; this calculator derives values — no inline blade formulas,
 * no averaging of daily rates, no silent divide-by-zero, no zero-substitute for missing.
 */
final class Ga4FormulaCalculator
{
    public const string FORMULA_ENGAGEMENT_RATE = 'FORMULA_GA4_ENGAGEMENT_RATE';

    public const string FORMULA_AVG_ENGAGEMENT_TIME = 'FORMULA_GA4_AVG_ENGAGEMENT_TIME';

    public const string FORMULA_VIEWS_PER_SESSION = 'FORMULA_GA4_VIEWS_PER_SESSION';

    public const string FORMULA_CHANNEL_SHARE = 'FORMULA_GA4_CHANNEL_SHARE';

    public const string FORMULA_DEVICE_SHARE = 'FORMULA_GA4_DEVICE_SHARE';

    public const string FORMULA_UTM_UNAVAILABLE_PCT = 'FORMULA_GA4_UTM_UNAVAILABLE_PCT';

    public const string FORMULA_PERIOD_RELATIVE_CHANGE = 'FORMULA_PERIOD_RELATIVE_CHANGE';

    /**
     * Formula IDs this calculator implements, asserted present in the frozen registry.
     *
     * @var list<string>
     */
    private const array REQUIRED_FORMULA_IDS = [
        self::FORMULA_ENGAGEMENT_RATE,
        self::FORMULA_AVG_ENGAGEMENT_TIME,
        self::FORMULA_VIEWS_PER_SESSION,
        self::FORMULA_CHANNEL_SHARE,
        self::FORMULA_DEVICE_SHARE,
        self::FORMULA_UTM_UNAVAILABLE_PCT,
        self::FORMULA_PERIOD_RELATIVE_CHANGE,
    ];

    public function __construct(
        private readonly FormulaRegistryLoader $registry,
    ) {}

    /**
     * Fail fast when the frozen registry no longer defines a formula this calculator relies on.
     */
    public function assertFormulasAvailable(): void
    {
        foreach (self::REQUIRED_FORMULA_IDS as $id) {
            $this->registry->formula($id);
        }
    }

    /**
     * engagedSessions / sessions. FORMULA_GA4_ENGAGEMENT_RATE.
     */
    public function engagementRate(?int $engagedSessions, ?int $sessions): FormulaResult
    {
        return $this->ratio(
            $engagedSessions === null ? null : (float) $engagedSessions,
            $sessions === null ? null : (float) $sessions,
        );
    }

    /**
     * userEngagementDuration / activeUsers (seconds). FORMULA_GA4_AVG_ENGAGEMENT_TIME.
     */
    public function avgEngagementTime(?float $userEngagementDuration, ?int $activeUsers): FormulaResult
    {
        return $this->ratio(
            $userEngagementDuration,
            $activeUsers === null ? null : (float) $activeUsers,
        );
    }

    /**
     * screenPageViews / sessions. FORMULA_GA4_VIEWS_PER_SESSION.
     */
    public function viewsPerSession(?int $screenPageViews, ?int $sessions): FormulaResult
    {
        return $this->ratio(
            $screenPageViews === null ? null : (float) $screenPageViews,
            $sessions === null ? null : (float) $sessions,
        );
    }

    /**
     * channel sessions / property sessions. FORMULA_GA4_CHANNEL_SHARE.
     */
    public function channelShare(?int $channelSessions, ?int $propertySessions): FormulaResult
    {
        return $this->ratio(
            $channelSessions === null ? null : (float) $channelSessions,
            $propertySessions === null ? null : (float) $propertySessions,
        );
    }

    /**
     * device sessions / property sessions. FORMULA_GA4_DEVICE_SHARE.
     */
    public function deviceShare(?int $deviceSessions, ?int $propertySessions): FormulaResult
    {
        return $this->ratio(
            $deviceSessions === null ? null : (float) $deviceSessions,
            $propertySessions === null ? null : (float) $propertySessions,
        );
    }

    /**
     * sessions with campaign in {(not set), empty} / sessions. FORMULA_GA4_UTM_UNAVAILABLE_PCT.
     */
    public function utmUnavailablePct(?int $unavailableSessions, ?int $totalSessions): FormulaResult
    {
        return $this->ratio(
            $unavailableSessions === null ? null : (float) $unavailableSessions,
            $totalSessions === null ? null : (float) $totalSessions,
        );
    }

    /**
     * (current - previous) / previous. FORMULA_PERIOD_RELATIVE_CHANGE.
     * Never Infinity% — previous=0 & current>0 is explicitly UNDEFINED_RELATIVE_CHANGE.
     */
    public function periodRelativeChange(?float $current, ?float $previous): FormulaResult
    {
        if ($current === null || $previous === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        if ($previous == 0.0) {
            return $current == 0.0
                ? FormulaResult::state(FormulaResult::STATE_UNDEFINED)
                : FormulaResult::state(FormulaResult::STATE_UNDEFINED_RELATIVE_CHANGE);
        }

        return FormulaResult::value(($current - $previous) / $previous);
    }

    /**
     * Shared MP_RATIO_STANDARD semantics: zero-denominator → undefined, never a silent 0.
     */
    private function ratio(?float $numerator, ?float $denominator): FormulaResult
    {
        if ($numerator === null || $denominator === null) {
            return FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        }

        if ($denominator == 0.0) {
            return $numerator == 0.0
                ? FormulaResult::state(FormulaResult::STATE_UNDEFINED)
                : FormulaResult::state(FormulaResult::STATE_UNDEFINED_ZERO_DENOMINATOR);
        }

        return FormulaResult::value($numerator / $denominator);
    }
}
