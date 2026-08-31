<?php

namespace MoxDop\Website\Discovery;

/**
 * Bounded public Discovery configuration (Website module owned).
 */
final class DiscoveryConfig
{
    public const string VERSION = 'website-public-discovery-v2';

    public const string MODULE_ID = 'website-discovery';

    public const string EVIDENCE_SITE_SUMMARY = 'website_public_site_summary';

    public const string EVIDENCE_PAGE_SNAPSHOT = 'website_public_page_snapshot';

    public const string EVIDENCE_COMPETITOR_CANDIDATES = 'website_public_competitor_candidates';

    /** Maximum number of HTML pages analyzed in a single public crawl run. */
    public const int MAX_PAGES = 15;

    /** Maximum URL observations collected by the resumable Website Collection Engine. */
    public const int MAX_COLLECTION_PAGES = 5000;

    /** Maximum number of sitemap documents followed from one website. */
    public const int MAX_SITEMAP_FILES = 50;

    /** Maximum number of page URLs accepted from sitemap urlsets in one run. */
    public const int MAX_SITEMAP_URLS = 5000;

    /** Maximum sitemap-index nesting depth. */
    public const int MAX_SITEMAP_DEPTH = 3;

    public const int MAX_REDIRECTS = 5;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int TIMEOUT_SECONDS = 12;

    public const int MAX_RESPONSE_BYTES = 1_500_000;

    /** Maximum complete response retained by the resumable Website Collection Engine. */
    public const int MAX_COLLECTION_RESPONSE_BYTES = 10_000_000;

    public const int MAX_TOTAL_BYTES = 8_000_000;

    /** Aggregate HTML budget for one resumable full-site collection. */
    public const int MAX_COLLECTION_TOTAL_BYTES = 2_000_000_000;

    public const string USER_AGENT = 'MoxDOP-PublicDiscovery/1.0 (+https://moximu.com; read-only public discovery)';

    /**
     * Production crawling must never invent likely page paths. The root is the only seed;
     * additional URLs must come from robots/sitemaps, redirects, or real same-site links.
     *
     * @return list<string>
     */
    public static function preferredPathHints(): array
    {
        return ['/'];
    }

    /** @return list<string> */
    public static function sitemapFallbackPaths(): array
    {
        return [
            '/sitemap.xml',
            '/sitemap_index.xml',
            '/sitemaps.xml',
        ];
    }
}
