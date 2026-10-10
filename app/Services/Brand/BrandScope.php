<?php

namespace App\Services\Brand;

use App\Models\DigitalAsset;
use Illuminate\Support\Facades\DB;

/**
 * "Which accounts belong to this brand" answered in one place (yakup, 2026-10-10 "verinin özü = marka"). An account
 * belongs to a brand through an active binding on one of the brand's (not deleted) assets, whatever asset type it was
 * bound to: a Search Console property on a separate "gsc" asset is the brand's as much as one on the website asset.
 * Types are core resource types (search_console, ga4, google_ads, meta_ads, google_business_profile).
 */
final class BrandScope
{
    /** @return list<int> the brand's assets, not deleted */
    public static function assetIds(int $brandId): array
    {
        return DigitalAsset::query()->where('brand_id', $brandId)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Accounts of a type bound to any asset of the brand.
     *
     * @return list<int>
     */
    public static function resources(int $brandId, string $type): array
    {
        return array_values(array_unique(array_column(self::rows($brandId, $type), 'id')));
    }

    /**
     * The accounts of a type that measure one website: the ones bound to the site itself; else the brand's accounts
     * of that type whose address names the site's host (a Search Console property); else, when the site is the
     * brand's only website, all of the brand's accounts of that type.
     *
     * @return list<int>
     */
    public static function siteResources(DigitalAsset $site, string $type): array
    {
        $rows = $site->brand_id !== null ? self::rows((int) $site->brand_id, $type) : self::rows(null, $type, (int) $site->id);
        $own = array_values(array_unique(array_column(array_filter($rows, fn (array $r): bool => $r['asset_id'] === (int) $site->id), 'id')));
        if ($own !== [] || $site->brand_id === null) {
            return $own;
        }
        $host = self::host((string) ($site->domain ?: $site->primary_url));
        $named = $host === '' ? [] : array_values(array_unique(array_column(array_filter($rows, fn (array $r): bool => self::host($r['external_id']) === $host), 'id')));
        if ($named !== []) {
            return $named;
        }
        $websites = DigitalAsset::query()->where('brand_id', $site->brand_id)->where('type', 'website')->count();

        return $websites === 1 ? array_values(array_unique(array_column($rows, 'id'))) : [];
    }

    /** "sc-domain:www.x.com", "https://x.com/tr/", "x.com" → "x.com" */
    public static function host(string $address): string
    {
        $address = strtolower(trim(preg_replace('/^sc-domain:/i', '', $address) ?? ''));
        $host = (string) (parse_url(str_contains($address, '://') ? $address : 'https://'.$address, PHP_URL_HOST) ?: '');

        return (string) preg_replace('/^www\./', '', $host);
    }

    /** @return list<array{id: int, asset_id: int, external_id: string}> */
    private static function rows(?int $brandId, string $type, ?int $assetId = null): array
    {
        return DB::table('core_asset_bindings as b')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->join('core_external_resources as r', 'r.id', '=', 'b.external_resource_id')
            ->when($brandId !== null, fn ($q) => $q->where('a.brand_id', $brandId), fn ($q) => $q->where('a.id', $assetId))
            ->where('b.status', 'active')->whereNull('a.deleted_at')->where('r.resource_type', $type)
            ->orderBy('b.external_resource_id')->get(['b.external_resource_id', 'b.digital_asset_id', 'r.external_id'])
            ->map(fn (object $r): array => ['id' => (int) $r->external_resource_id, 'asset_id' => (int) $r->digital_asset_id, 'external_id' => (string) $r->external_id])->all();
    }
}
