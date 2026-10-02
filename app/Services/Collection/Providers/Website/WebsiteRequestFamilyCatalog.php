<?php

namespace App\Services\Collection\Providers\Website;

use InvalidArgumentException;

/**
 * Contract-driven Website request-family definitions (Registry WEB_RF_*).
 *
 * WordPress inventory is executed by the authenticated WordPress Connector executor while
 * the provider-neutral crawler remains the canonical externally-observable source.
 */
final class WebsiteRequestFamilyCatalog
{
    public const string FAMILY_HTTP_HTML_DIAGNOSIS = 'WEB_RF_HTTP_HTML_DIAGNOSIS';

    public const string FAMILY_PAGESPEED = 'WEB_RF_PAGESPEED';

    public const string FAMILY_DNS_TLS = 'WEB_RF_DNS_TLS';

    public const string FAMILY_PUBLIC_CRAWL = 'WEB_RF_PUBLIC_CRAWL';

    public const string FAMILY_WP_REST = 'WEB_RF_WP_REST';

    /**
     * @return list<string>
     */
    public static function supportedFamilies(): array
    {
        return [...self::publicFamilies(), ...self::connectorFamilies()];
    }

    /** @return list<string> */
    public static function publicFamilies(): array
    {
        return [
            self::FAMILY_HTTP_HTML_DIAGNOSIS,
            self::FAMILY_PAGESPEED,
            self::FAMILY_DNS_TLS,
            self::FAMILY_PUBLIC_CRAWL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function connectorFamilies(): array
    {
        return [
            self::FAMILY_WP_REST,
        ];
    }

    /**
     * @return array{
     *   kind: string,
     *   dataset_ids: list<string>,
     *   requires_date_range: bool,
     *   preferred_mode: 'sync'|'sync_then_async'|'async',
     *   high_cardinality: bool
     * }
     */
    public static function definition(string $familyId): array
    {
        return match ($familyId) {
            self::FAMILY_HTTP_HTML_DIAGNOSIS => [
                'kind' => 'http_html_diagnosis',
                'dataset_ids' => [
                    'website_url',
                    'website_http_snapshot',
                    'website_html_snapshot',
                    'website_metadata_snapshot',
                    'website_heading_snapshot',
                    'website_schema_snapshot',
                    'website_content_stats',
                    'website_link_edge',
                    'website_crawl_issue_snapshot',
                ],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
            ],
            self::FAMILY_PAGESPEED => [
                'kind' => 'pagespeed',
                'dataset_ids' => ['website_performance_measurement'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
            ],
            self::FAMILY_DNS_TLS => [
                'kind' => 'dns_tls',
                'dataset_ids' => ['website_infra_snapshot'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
            ],
            self::FAMILY_PUBLIC_CRAWL => [
                'kind' => 'public_crawl',
                'dataset_ids' => [
                    'website_url',
                    'website_http_snapshot',
                    'website_html_snapshot',
                    'website_metadata_snapshot',
                    'website_heading_snapshot',
                    'website_schema_snapshot',
                    'website_content_stats',
                    'website_link_edge',
                    'website_crawl_issue_snapshot',
                ],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => true,
            ],
            self::FAMILY_WP_REST => [
                'kind' => 'wordpress_connector',
                'dataset_ids' => [
                    'website_cms_site_snapshot',
                    'website_cms_object_snapshot',
                    'website_cms_extension_snapshot',
                    'website_cms_taxonomy_snapshot',
                    'website_cms_seo_snapshot',
                ],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => true,
            ],
            default => throw new InvalidArgumentException("Unknown Website request family [{$familyId}]"),
        };
    }
}
