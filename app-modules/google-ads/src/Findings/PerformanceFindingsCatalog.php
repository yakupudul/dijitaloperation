<?php

namespace MoxDop\GoogleAds\Findings;

/**
 * Conservative Google Ads Finding thresholds (sample gates + within-account facts).
 * No universal folklore CTR/CPC/CPA magic numbers as “bad” truth.
 * Tune only inside the google-ads module — Core stays threshold-free.
 */
final class PerformanceFindingsCatalog
{
    public const string SOURCE_MODULE = 'google-ads';

    public const string RULE_CONVERSIONS_DECLINE = 'google-ads:conversions-decline';

    public const string RULE_CPA_DETERIORATION = 'google-ads:cpa-deterioration';

    public const string RULE_SPEND_UP_CONVERSIONS_DOWN = 'google-ads:spend-up-conversions-down';

    public const string RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS = 'google-ads:campaign-spend-zero-conversions';

    public const string RULE_SEARCH_TERM_WASTE_CANDIDATE = 'google-ads:search-term-waste-candidate';

    public const string RULE_SEARCH_TERM_OPPORTUNITY_CANDIDATE = 'google-ads:search-term-opportunity-candidate';

    public const string RULE_MEASUREMENT_CONFIG_RISK = 'google-ads:measurement-config-risk';

    public const string RULE_LANDING_URL_COVERAGE_RISK = 'google-ads:landing-url-coverage-risk';

    /** @var list<string> */
    public const array ACCOUNT_RULE_IDS = [
        self::RULE_CONVERSIONS_DECLINE,
        self::RULE_CPA_DETERIORATION,
        self::RULE_SPEND_UP_CONVERSIONS_DOWN,
    ];

    /** @var list<string> */
    public const array CAMPAIGN_RULE_IDS = [
        self::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS,
    ];

    /** @var list<string> */
    public const array SEARCH_TERM_RULE_IDS = [
        self::RULE_SEARCH_TERM_WASTE_CANDIDATE,
        self::RULE_SEARCH_TERM_OPPORTUNITY_CANDIDATE,
    ];

    /** @var list<string> */
    public const array MEASUREMENT_RULE_IDS = [
        self::RULE_MEASUREMENT_CONFIG_RISK,
    ];

    /** @var list<string> */
    public const array LANDING_RULE_IDS = [
        self::RULE_LANDING_URL_COVERAGE_RISK,
    ];

    public const float CONVERSIONS_PREV_MIN = 10.0;

    public const float CONVERSIONS_ABS_DROP_MIN = 5.0;

    public const float CONVERSIONS_PCT_DROP_MIN = 30.0;

    public const float CPA_PREV_CONVERSIONS_MIN = 10.0;

    public const float CPA_CURRENT_CONVERSIONS_MIN = 5.0;

    public const float CPA_PREV_COST_MIN = 50.0;

    public const float CPA_PCT_INCREASE_MIN = 40.0;

    public const float SPEND_UP_PREV_COST_MIN = 50.0;

    public const float SPEND_UP_PCT_MIN = 20.0;

    public const float SPEND_UP_CONVERSIONS_PCT_DROP_MIN = 20.0;

    public const float CAMPAIGN_COST_MIN = 50.0;

    public const float CAMPAIGN_CLICKS_MIN = 30.0;

    public const int CAMPAIGN_FINDINGS_MAX = 10;

    /** Sample gate for search-term waste candidates (investigation, not auto-negate). */
    public const float SEARCH_WASTE_COST_MIN = 25.0;

    public const float SEARCH_WASTE_CLICKS_MIN = 20.0;

    public const int SEARCH_WASTE_FINDINGS_MAX = 15;

    /** Opportunity candidates require observed conversions and non-targeted status when available. */
    public const float SEARCH_OPP_CONVERSIONS_MIN = 1.0;

    public const float SEARCH_OPP_CLICKS_MIN = 5.0;

    public const int SEARCH_OPP_FINDINGS_MAX = 10;
}
