<?php

namespace App\Services\Integrations\DataForSeo;

/**
 * Explicit approved DataForSEO API v3 endpoint identifiers.
 * Collectors must use allowlisted methods — no arbitrary endpoint console.
 */
final class DataForSeoEndpointAllowlist
{
    public const string APPENDIX_USER_DATA = 'appendix/user_data';

    /** Free Labs market directory (not charged). */
    public const string LABS_LOCATIONS_AND_LANGUAGES = 'dataforseo_labs/locations_and_languages';

    /** Free SERP location directory for Türkiye (cities and districts for local SERP checks). */
    public const string SERP_GOOGLE_LOCATIONS_TR = 'serp/google/locations/tr';

    /** Paid — Website ranked organic keywords. */
    public const string LABS_GOOGLE_RANKED_KEYWORDS_LIVE = 'dataforseo_labs/google/ranked_keywords/live';

    /** Paid — Website keyword ideas for domain. */
    public const string LABS_GOOGLE_KEYWORDS_FOR_SITE_LIVE = 'dataforseo_labs/google/keywords_for_site/live';

    /** Paid — organic competitor domains for a target domain (Labs Google). */
    public const string LABS_GOOGLE_COMPETITORS_DOMAIN_LIVE = 'dataforseo_labs/google/competitors_domain/live';

    /** Paid — Sales Intent Radar V1 public SERP (explicit operator run only). */
    public const string SERP_GOOGLE_ORGANIC_LIVE_REGULAR = 'serp/google/organic/live/regular';

    /** Paid — explicit keyword search-volume and monthly-trend observation. */
    public const string KEYWORDS_DATA_GOOGLE_ADS_SEARCH_VOLUME_LIVE = 'keywords_data/google_ads/search_volume/live';

    /** Paid — bounded related query expansion from explicit seeds. */
    public const string LABS_GOOGLE_KEYWORD_IDEAS_LIVE = 'dataforseo_labs/google/keyword_ideas/live';

    /** Paid — Google Maps SERP, standard queue (Faz 8 harita grid takibi, dış denetim). */
    public const string SERP_GOOGLE_MAPS_TASK_POST = 'serp/google/maps/task_post';

    /** Paid — Google reviews of one business, standard queue (Faz 8 yorum istihbaratı). */
    public const string BUSINESS_DATA_GOOGLE_REVIEWS_TASK_POST = 'business_data/google/reviews/task_post';

    /** Paid — backlink summary of a target (Faz 8 backlink fırsat motoru). */
    public const string BACKLINKS_SUMMARY_LIVE = 'backlinks/summary/live';

    /** Paid — referring domains of a target. */
    public const string BACKLINKS_REFERRING_DOMAINS_LIVE = 'backlinks/referring_domains/live';

    /** Paid — domains linking to several targets (competitor intersection). */
    public const string BACKLINKS_DOMAIN_INTERSECTION_LIVE = 'backlinks/domain_intersection/live';

    /**
     * Result reads of queued tasks posted above: the task id is part of the path, so these are matched by
     * pattern (free reads; only the post is charged).
     */
    public const array TASK_GET_PATTERNS = [
        '#^serp/google/maps/task_get/advanced/[A-Za-z0-9-]{8,64}$#',
        '#^business_data/google/reviews/task_get/[A-Za-z0-9-]{8,64}$#',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::APPENDIX_USER_DATA,
            self::LABS_LOCATIONS_AND_LANGUAGES,
            self::SERP_GOOGLE_LOCATIONS_TR,
            self::LABS_GOOGLE_RANKED_KEYWORDS_LIVE,
            self::LABS_GOOGLE_KEYWORDS_FOR_SITE_LIVE,
            self::LABS_GOOGLE_COMPETITORS_DOMAIN_LIVE,
            self::SERP_GOOGLE_ORGANIC_LIVE_REGULAR,
            self::KEYWORDS_DATA_GOOGLE_ADS_SEARCH_VOLUME_LIVE,
            self::LABS_GOOGLE_KEYWORD_IDEAS_LIVE,
            self::SERP_GOOGLE_MAPS_TASK_POST,
            self::BUSINESS_DATA_GOOGLE_REVIEWS_TASK_POST,
            self::BACKLINKS_SUMMARY_LIVE,
            self::BACKLINKS_REFERRING_DOMAINS_LIVE,
            self::BACKLINKS_DOMAIN_INTERSECTION_LIVE,
        ];
    }

    public static function isAllowed(string $endpoint): bool
    {
        $normalized = ltrim(trim($endpoint), '/');
        if (in_array($normalized, self::all(), true)) {
            return true;
        }
        foreach (self::TASK_GET_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function assertAllowed(string $endpoint): string
    {
        $normalized = ltrim(trim($endpoint), '/');

        if (! self::isAllowed($normalized)) {
            throw new DataForSeoException(
                'DataForSEO endpoint is not allowlisted for MoxDOP: '.$normalized,
                kind: DataForSeoException::KIND_ENDPOINT_NOT_ALLOWED,
            );
        }

        return $normalized;
    }
}
