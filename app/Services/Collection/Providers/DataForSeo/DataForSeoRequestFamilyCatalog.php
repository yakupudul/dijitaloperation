<?php

namespace App\Services\Collection\Providers\DataForSeo;

use InvalidArgumentException;

/**
 * Contract-driven DataForSEO request-family definitions (Registry DFS-*).
 *
 * v2: only the free account / market families run here. The paid Labs families are retired (their registry rows
 * stay frozen); SERP top-10 and search volume run through App\Services\Intel\SerpResults / QueryVolumes.
 */
final class DataForSeoRequestFamilyCatalog
{
    public const string FAMILY_FREE_USER = 'DFS-FREE-USER';

    public const string FAMILY_FREE_MARKETS = 'DFS-FREE-MARKETS';

    public const string FAMILY_DOMAIN_INTERSECT = 'DFS-DOMAIN-INTERSECT-LIVE';

    public const string FAMILY_RELEVANT_PAGES = 'DFS-RELEVANT-PAGES-LIVE';

    public const string FAMILY_SERP_ORGANIC = 'DFS-SERP-ORGANIC';

    /**
     * @return list<string>
     */
    public static function supportedFamilies(): array
    {
        // v2: the paid Labs families are retired (their endpoints are no longer allowlisted); SERP top-10 and search
        // volume run through App\Services\Intel\SerpResults / QueryVolumes with their own caches.
        return [
            self::FAMILY_FREE_USER,
            self::FAMILY_FREE_MARKETS,
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
            default => throw new InvalidArgumentException("Unknown DataForSEO request family [{$familyId}]"),
        };
    }
}
