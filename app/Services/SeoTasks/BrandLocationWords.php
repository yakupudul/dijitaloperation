<?php

namespace App\Services\SeoTasks;

use App\Models\Brand;

/**
 * Folded location words for a brand: every Turkish province, the districts of the provinces the brand serves and the
 * brand's own service area names. Used to spot location-only page variants (doorway pages) and district words added
 * to a Business Profile name.
 */
final class BrandLocationWords
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $provinces = null;

    /** @var list<array<string, mixed>>|null */
    private static ?array $districts = null;

    /** @return list<string> */
    public static function for(?Brand $brand): array
    {
        self::$provinces ??= json_decode((string) @file_get_contents(resource_path('data/locations/provinces.json')), true) ?: [];
        self::$districts ??= json_decode((string) @file_get_contents(resource_path('data/locations/districts.json')), true) ?: [];
        $words = [];
        $provinceIds = [];
        $areaNames = [];
        foreach ($brand?->serviceAreas()->where('status', 'active')->get(['city_name', 'district_name']) ?? [] as $area) {
            foreach ([$area->city_name, $area->district_name] as $name) {
                if (filled($name)) {
                    $areaNames[] = SeoText::fold((string) $name);
                }
            }
        }
        foreach (self::$provinces as $province) {
            $slug = SeoText::fold((string) ($province['name'] ?? ''));
            array_push($words, ...explode(' ', $slug));
            if (in_array($slug, $areaNames, true)) {
                $provinceIds[] = $province['id'];
            }
        }
        foreach (self::$districts as $district) {
            if (in_array($district['provinceId'] ?? null, $provinceIds, true)) {
                array_push($words, ...explode(' ', SeoText::fold((string) $district['name'])));
            }
        }
        foreach ($areaNames as $name) {
            array_push($words, ...explode(' ', $name));
        }

        return array_values(array_unique(array_filter($words, fn (string $w): bool => mb_strlen($w) >= 3)));
    }
}
