<?php

namespace App\Services\Collection\Providers\DataForSeo;

use InvalidArgumentException;

/**
 * Contract-driven DataForSEO request-family definitions (Registry DFS-*).
 *
 * Paid families are operator-consented enrichment, never a routine scheduler.
 * Domain intersection and relevant pages remain DEFERRED. SERP organic is intentionally
 * outside the routine Collection Engine and is implemented by the manual Phase 7 workflow.
 */
final class DataForSeoRequestFamilyCatalog
{
    public const string FAMILY_FREE_USER = 'DFS-FREE-USER';

    public const string FAMILY_FREE_MARKETS = 'DFS-FREE-MARKETS';

    public const string FAMILY_RANKED_KEYWORDS = 'DFS-RK-LIVE';

    public const string FAMILY_KEYWORDS_FOR_SITE = 'DFS-KFS-LIVE';

    public const string FAMILY_COMPETITORS_DOMAIN = 'DFS-COMP-DOMAIN-LIVE';

    public const string FAMILY_DOMAIN_INTERSECT = 'DFS-DOMAIN-INTERSECT-LIVE';

    public const string FAMILY_RELEVANT_PAGES = 'DFS-RELEVANT-PAGES-LIVE';

    public const string FAMILY_SERP_ORGANIC = 'DFS-SERP-ORGANIC';

    /**
     * @return list<string>
     */
    public static function supportedFamilies(): array
    {
        return [
            self::FAMILY_FREE_USER,
            self::FAMILY_FREE_MARKETS,
            self::FAMILY_RANKED_KEYWORDS,
            self::FAMILY_KEYWORDS_FOR_SITE,
            self::FAMILY_COMPETITORS_DOMAIN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function paidFamilies(): array
    {
        return [
            self::FAMILY_RANKED_KEYWORDS,
            self::FAMILY_KEYWORDS_FOR_SITE,
            self::FAMILY_COMPETITORS_DOMAIN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function deferredFamilies(): array
    {
        return [
            self::FAMILY_DOMAIN_INTERSECT,
            self::FAMILY_RELEVANT_PAGES,
            self::FAMILY_SERP_ORGANIC,
        ];
    }

    /**
     * @return array{
     *   kind: string,
     *   dataset_ids: list<string>,
     *   requires_date_range: bool,
     *   preferred_mode: 'sync'|'sync_then_async'|'async',
     *   high_cardinality: bool,
     *   paid_call: bool,
     *   raw_only: bool
     * }
     */
    public static function definition(string $familyId): array
    {
        return match ($familyId) {
            self::FAMILY_FREE_USER => [
                'kind' => 'free_user',
                'dataset_ids' => ['dataforseo_raw_response'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
                'paid_call' => false,
                'raw_only' => true,
            ],
            self::FAMILY_FREE_MARKETS => [
                'kind' => 'free_markets',
                'dataset_ids' => ['dataforseo_raw_response'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
                'paid_call' => false,
                'raw_only' => true,
            ],
            self::FAMILY_RANKED_KEYWORDS => [
                'kind' => 'ranked_keywords',
                'dataset_ids' => ['dataforseo_ranked_keyword_snapshot'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
                'paid_call' => true,
                'raw_only' => false,
            ],
            self::FAMILY_KEYWORDS_FOR_SITE => [
                'kind' => 'keywords_for_site',
                'dataset_ids' => ['dataforseo_keyword_site_snapshot'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
                'paid_call' => true,
                'raw_only' => false,
            ],
            self::FAMILY_COMPETITORS_DOMAIN => [
                'kind' => 'competitors_domain',
                'dataset_ids' => ['dataforseo_competitor_domain_snapshot'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
                'paid_call' => true,
                'raw_only' => false,
            ],
            default => throw new InvalidArgumentException("Unknown DataForSEO request family [{$familyId}]"),
        };
    }
}
