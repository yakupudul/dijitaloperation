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
