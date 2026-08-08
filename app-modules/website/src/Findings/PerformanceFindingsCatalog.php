<?php

namespace MoxDop\Website\Findings;

/**
 * Conservative starter thresholds for Website Search Console + GA4 performance Findings.
 * Values are intentional sample gates — not product gospel. Tune in this module only.
 */
final class PerformanceFindingsCatalog
{
    public const string SOURCE_MODULE = 'website';

    public const string RULE_GSC_CLICKS_DECLINE = 'website:gsc:clicks-decline';

    public const string RULE_GSC_IMPRESSIONS_DECLINE = 'website:gsc:impressions-decline';

    public const string RULE_GSC_CTR_DECLINE = 'website:gsc:ctr-decline';

    public const string RULE_GSC_POSITION_WORSEN = 'website:gsc:position-worsen';

    public const string RULE_GA4_USERS_DECLINE = 'website:ga4:users-decline';

    public const string RULE_GA4_SESSIONS_DECLINE = 'website:ga4:sessions-decline';

    /** @var list<string> */
    public const array GSC_RULE_IDS = [
        self::RULE_GSC_CLICKS_DECLINE,
        self::RULE_GSC_IMPRESSIONS_DECLINE,
        self::RULE_GSC_CTR_DECLINE,
        self::RULE_GSC_POSITION_WORSEN,
    ];

    /** @var list<string> */
    public const array GA4_RULE_IDS = [
        self::RULE_GA4_USERS_DECLINE,
        self::RULE_GA4_SESSIONS_DECLINE,
    ];

    // Search Console — period totals from gsc_performance_summary
    public const float GSC_CLICKS_PREV_MIN = 100.0;

    public const float GSC_CLICKS_ABS_DROP_MIN = 20.0;

    public const float GSC_CLICKS_PCT_DROP_MIN = 20.0;

    public const float GSC_IMPRESSIONS_PREV_MIN = 500.0;

    public const float GSC_IMPRESSIONS_ABS_DROP_MIN = 100.0;

    public const float GSC_IMPRESSIONS_PCT_DROP_MIN = 20.0;

    public const float GSC_CTR_PREV_IMPRESSIONS_MIN = 500.0;

    /** Absolute CTR drop in ratio points (0.005 = 0.5 percentage points). */
    public const float GSC_CTR_ABS_DROP_MIN = 0.005;

    public const float GSC_POSITION_PREV_IMPRESSIONS_MIN = 500.0;

    /** Average position worsening (higher is worse). */
    public const float GSC_POSITION_WORSEN_MIN = 1.0;

    // GA4 — period totals from ga4_performance_summary
    public const float GA4_USERS_PREV_MIN = 50.0;

    public const float GA4_USERS_ABS_DROP_MIN = 15.0;

    public const float GA4_USERS_PCT_DROP_MIN = 20.0;

    public const float GA4_SESSIONS_PREV_MIN = 50.0;

    public const float GA4_SESSIONS_ABS_DROP_MIN = 15.0;

    public const float GA4_SESSIONS_PCT_DROP_MIN = 20.0;
}
