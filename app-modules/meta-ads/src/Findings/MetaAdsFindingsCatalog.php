<?php

namespace MoxDop\MetaAds\Findings;

/**
 * Conservative Meta Ads Finding thresholds (sample gates only).
 * No universal CTR/CPC/CPM/frequency folklore thresholds.
 */
final class MetaAdsFindingsCatalog
{
    public const string SOURCE_MODULE = 'meta-ads';

    public const string RULE_SPEND_WITHOUT_PRIMARY_RESULT = 'meta-ads:spend-without-primary-result';

    public const string RULE_DELIVERY_WITHOUT_RESOLVED_RESULT = 'meta-ads:delivery-without-resolved-result';

    public const string RULE_CAMPAIGN_INACTIVE_WITH_RECENT_SPEND = 'meta-ads:campaign-inactive-with-context';

    /** @var list<string> */
    public const array CAMPAIGN_RULE_IDS = [
        self::RULE_SPEND_WITHOUT_PRIMARY_RESULT,
        self::RULE_DELIVERY_WITHOUT_RESOLVED_RESULT,
        self::RULE_CAMPAIGN_INACTIVE_WITH_RECENT_SPEND,
    ];

    public const float SPEND_MIN = 50.0;

    public const float IMPRESSIONS_MIN = 500.0;

    public const float CLICKS_MIN = 25.0;

    public const int CAMPAIGN_FINDINGS_MAX = 12;
}
