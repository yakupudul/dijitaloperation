<?php

namespace App\Support\Performance;

/**
 * Parameterized Prompt 65 benchmark profiles.
 * These are NOT product capacity limits.
 *
 * @phpstan-type Profile array{
 *     id: string,
 *     customers: int,
 *     brands_per_customer: int,
 *     assets_per_brand: int,
 *     gsc_rows: int,
 *     ads_rows: int,
 *     gsc_days: int,
 *     ads_days: int,
 *     purpose: string
 * }
 */
final class BenchmarkProfiles
{
    public const AGENCY_20 = 'AGENCY_20';

    public const AGENCY_100 = 'AGENCY_100';

    public const HIGH_VOLUME_GSC = 'HIGH_VOLUME_GSC';

    public const HIGH_VOLUME_GOOGLE_ADS = 'HIGH_VOLUME_GOOGLE_ADS';

    public const MIXED_BACKGROUND_LOAD = 'MIXED_BACKGROUND_LOAD';

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return [
            self::AGENCY_20,
            self::AGENCY_100,
            self::HIGH_VOLUME_GSC,
            self::HIGH_VOLUME_GOOGLE_ADS,
            self::MIXED_BACKGROUND_LOAD,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $overrides
     * @return Profile
     */
    public static function resolve(string $id, array $overrides = []): array
    {
        $base = match ($id) {
            self::AGENCY_20 => [
                'id' => self::AGENCY_20,
                'customers' => 20,
                'brands_per_customer' => 1,
                'assets_per_brand' => 1,
                'gsc_rows' => 2_000,
                'ads_rows' => 2_000,
                'gsc_days' => 28,
                'ads_days' => 28,
                'purpose' => 'Agency portfolio control-plane + modest data-plane load',
            ],
            self::AGENCY_100 => [
                'id' => self::AGENCY_100,
                'customers' => 100,
                'brands_per_customer' => 1,
                'assets_per_brand' => 1,
                'gsc_rows' => 5_000,
                'ads_rows' => 5_000,
                'gsc_days' => 28,
                'ads_days' => 28,
                'purpose' => 'Larger agency portfolio control-plane scale',
            ],
            self::HIGH_VOLUME_GSC => [
                'id' => self::HIGH_VOLUME_GSC,
                'customers' => 1,
                'brands_per_customer' => 1,
                'assets_per_brand' => 1,
                'gsc_rows' => 50_000,
                'ads_rows' => 0,
                'gsc_days' => 90,
                'ads_days' => 0,
                'purpose' => 'High-cardinality Search Console query/page volume',
            ],
            self::HIGH_VOLUME_GOOGLE_ADS => [
                'id' => self::HIGH_VOLUME_GOOGLE_ADS,
                'customers' => 1,
                'brands_per_customer' => 1,
                'assets_per_brand' => 1,
                'gsc_rows' => 0,
                'ads_rows' => 50_000,
                'gsc_days' => 0,
                'ads_days' => 90,
                'purpose' => 'High-cardinality Google Ads search-term volume',
            ],
            self::MIXED_BACKGROUND_LOAD => [
                'id' => self::MIXED_BACKGROUND_LOAD,
                'customers' => 20,
                'brands_per_customer' => 1,
                'assets_per_brand' => 1,
                'gsc_rows' => 10_000,
                'ads_rows' => 10_000,
                'gsc_days' => 30,
                'ads_days' => 30,
                'purpose' => 'Foreground reads + synthetic background job coexistence',
            ],
            default => throw new \InvalidArgumentException("Unknown benchmark profile: {$id}"),
        };

        foreach (['customers', 'brands_per_customer', 'assets_per_brand', 'gsc_rows', 'ads_rows', 'gsc_days', 'ads_days'] as $key) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $base[$key] = max(0, (int) $overrides[$key]);
            }
        }

        return $base;
    }
}
