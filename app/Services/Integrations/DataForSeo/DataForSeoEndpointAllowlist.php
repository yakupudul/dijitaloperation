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

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::APPENDIX_USER_DATA,
            self::LABS_LOCATIONS_AND_LANGUAGES,
            self::LABS_GOOGLE_RANKED_KEYWORDS_LIVE,
            self::LABS_GOOGLE_KEYWORDS_FOR_SITE_LIVE,
            self::LABS_GOOGLE_COMPETITORS_DOMAIN_LIVE,
            self::SERP_GOOGLE_ORGANIC_LIVE_REGULAR,
            self::KEYWORDS_DATA_GOOGLE_ADS_SEARCH_VOLUME_LIVE,
            self::LABS_GOOGLE_KEYWORD_IDEAS_LIVE,
        ];
    }

    public static function isAllowed(string $endpoint): bool
    {
        $normalized = ltrim(trim($endpoint), '/');

        return in_array($normalized, self::all(), true);
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
