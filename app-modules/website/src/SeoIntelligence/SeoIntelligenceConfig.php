<?php

namespace MoxDop\Website\SeoIntelligence;

/**
 * Centralized Website SEO Intelligence limits and TTL (MoxDOP policy).
 */
final class SeoIntelligenceConfig
{
    public const string EVIDENCE_RANKED_SUMMARY = 'dataforseo_ranked_keywords_summary';

    public const string EVIDENCE_RANKED_ROWS = 'dataforseo_ranked_keywords';

    public const string EVIDENCE_KEYWORD_OPPORTUNITIES = 'dataforseo_keyword_opportunities';

    public const string CAPABILITY_RANKED = 'dataforseo_ranked_keywords';

    public const string CAPABILITY_KEYWORDS_FOR_SITE = 'dataforseo_keywords_for_site';

    public static function rankedKeywordsTtlDays(): int
    {
        return max(1, (int) config('moxdop.seo_intelligence.ranked_keywords.ttl_days', 5));
    }

    public static function rankedKeywordsLimit(): int
    {
        return max(1, min(1000, (int) config('moxdop.seo_intelligence.ranked_keywords.limit', 100)));
    }

    public static function rankedKeywordsUseCase(): string
    {
        return (string) config('moxdop.seo_intelligence.ranked_keywords.use_case', 'website_ranked_keywords');
    }

    public static function keywordsForSiteTtlDays(): int
    {
        return max(1, (int) config('moxdop.seo_intelligence.keywords_for_site.ttl_days', 7));
    }

    public static function keywordsForSiteLimit(): int
    {
        return max(1, min(1000, (int) config('moxdop.seo_intelligence.keywords_for_site.limit', 100)));
    }

    public static function keywordsForSiteMinVolume(): int
    {
        return max(0, (int) config('moxdop.seo_intelligence.keywords_for_site.min_search_volume', 10));
    }

    public static function keywordsForSiteUseCase(): string
    {
        return (string) config('moxdop.seo_intelligence.keywords_for_site.use_case', 'website_keywords_for_site');
    }

    public static function opportunitiesMaxRows(): int
    {
        return max(1, (int) config('moxdop.seo_intelligence.opportunities.max_rows', 40));
    }

    public static function highVolumeThreshold(): int
    {
        return max(1, (int) config('moxdop.seo_intelligence.opportunities.high_volume', 500));
    }

    public static function mediumVolumeThreshold(): int
    {
        return max(1, (int) config('moxdop.seo_intelligence.opportunities.medium_volume', 100));
    }

    public static function weakRankMin(): int
    {
        return max(1, (int) config('moxdop.seo_intelligence.opportunities.weak_rank_min', 11));
    }

    public static function weakRankMax(): int
    {
        return max(1, (int) config('moxdop.seo_intelligence.opportunities.weak_rank_max', 30));
    }
}
