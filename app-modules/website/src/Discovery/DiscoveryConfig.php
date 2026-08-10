<?php

namespace MoxDop\Website\Discovery;

/**
 * Bounded public Discovery configuration (Website module owned).
 */
final class DiscoveryConfig
{
    public const string VERSION = 'website-public-discovery-v1';

    public const string MODULE_ID = 'website-discovery';

    public const string EVIDENCE_SITE_SUMMARY = 'website_public_site_summary';

    public const string EVIDENCE_PAGE_SNAPSHOT = 'website_public_page_snapshot';

    public const string EVIDENCE_COMPETITOR_CANDIDATES = 'website_public_competitor_candidates';

    public const int MAX_PAGES = 15;

    public const int MAX_REDIRECTS = 5;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int TIMEOUT_SECONDS = 12;

    public const int MAX_RESPONSE_BYTES = 1_500_000;

    public const int MAX_TOTAL_BYTES = 8_000_000;

    public const string USER_AGENT = 'MoxDOP-PublicDiscovery/1.0 (+https://moximu.com; read-only public discovery)';

    /**
     * @return list<string>
     */
    public static function preferredPathHints(): array
    {
        return [
            '/',
            '/about',
            '/about-us',
            '/services',
            '/products',
            '/contact',
            '/contact-us',
            '/locations',
            '/location',
            '/our-services',
        ];
    }
}
