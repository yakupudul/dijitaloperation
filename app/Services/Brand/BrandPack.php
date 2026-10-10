<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\Site\SiteScope;

/**
 * What every AI operation is told about the brand, built one way (yakup, 2026-10-10 "verinin özü = marka"): the same
 * services (★ first, the brand's display names), the same active places, the same languages, the same sector and brand
 * rules (all of them, not the first 8 or 12) and the same Marka bilgi kartı lines. Operations keep their own input
 * keys; only where the values come from is shared.
 */
final class BrandPack
{
    /** Rules sent to an AI operation at most (a sector pack has about 30). */
    public const int RULES = 60;

    /** @return list<array{name: string, priority: string}> active services, ★ main first */
    public static function services(?Brand $brand): array
    {
        if ($brand === null) {
            return [];
        }

        return BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->orderBy('id')->get()
            ->map(fn (BrandOffering $o): array => ['name' => $o->displayName(), 'priority' => $o->isMain() ? 'main' : 'secondary'])
            ->filter(fn (array $o): bool => $o['name'] !== '')->unique('name')->values()->all();
    }

    /** @return list<string> */
    public static function serviceNames(?Brand $brand, int $limit = 30): array
    {
        return array_slice(array_column(self::services($brand), 'name'), 0, $limit);
    }

    /** @return list<array{name: string, physical_branch: bool}> active places, branches first */
    public static function areas(?Brand $brand, int $limit = 30): array
    {
        if ($brand === null) {
            return [];
        }

        return BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderByDesc('physical_branch')->orderBy('id')->limit($limit)->get()
            ->map(fn (BrandServiceArea $a): array => ['name' => $a->displayName(), 'physical_branch' => (bool) $a->physical_branch])
            ->filter(fn (array $a): bool => $a['name'] !== '')->values()->all();
    }

    /** @return list<string> the short place words written in titles ("Çankaya", "İzmir"), branches first */
    public static function areaNames(?Brand $brand, int $limit = 12): array
    {
        if ($brand === null) {
            return [];
        }

        return BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderByDesc('physical_branch')->orderBy('id')->get()
            ->map(fn (BrandServiceArea $a): string => trim((string) ($a->district_name ?: $a->city_name ?: $a->name)))
            ->filter()->unique()->take($limit)->values()->all();
    }

    /**
     * The brand's languages as set on the brand; else the languages its websites' pages are written in (main first).
     *
     * @return list<string>
     */
    public static function languages(?Brand $brand): array
    {
        if ($brand === null) {
            return [];
        }
        $set = array_values(array_filter(array_map(fn ($l): string => trim((string) $l), (array) ($brand->languages ?? []))));
        if ($set !== []) {
            return $set;
        }
        $out = [];
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get() as $site) {
            array_push($out, ...SiteScope::languages($site));
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> the sector and brand rules, every one of them */
    public static function rules(?Brand $brand): array
    {
        if ($brand === null) {
            return [];
        }

        return app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->map(fn ($m): string => trim((string) $m))
            ->filter()->unique()->values()->take(self::RULES)->all();
    }

    /**
     * Lines of the Marka bilgi kartı (the operator's text where they wrote one).
     *
     * @param  list<string>|null  $keys  only these fields
     * @return array<string, list<string>>
     */
    public static function card(?Brand $brand, ?array $keys = null): array
    {
        if ($brand === null) {
            return [];
        }
        $card = app(BrandFacts::class)->forPrompt($brand);

        return $keys === null ? $card : array_intersect_key($card, array_flip($keys));
    }
}
