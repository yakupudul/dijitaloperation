<?php

namespace App\Services\Site;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Support\Options\LocationOptions;

/**
 * Hizmet bölgeleri from the site itself (no AI): Turkish provinces and districts named in the titles / H1s of the
 * site's service and location pages. A district name that exists in several provinces counts only when one of those
 * provinces is named on the site too. Template / archive sections ("/kws/…" keyword pages) are never read: their place
 * names are keyword spam, not where the business works. Only names on at least MIN_PAGES pages are proposed.
 */
final class SiteAreas
{
    public const int MIN_PAGES = 2;

    public const int MAX = 10;

    /** @return list<array{city: string, district: ?string, label: string, pages: int}> */
    public static function detect(DigitalAsset $site): array
    {
        $bulk = PageCategorizer::bulkSections((int) $site->id);
        $names = [];
        Page::query()->where('website_asset_id', $site->id)->whereIn('category', ['hizmet', 'lokasyon', 'kurumsal'])->orderBy('id')->limit(3000)
            ->get(['url', 'path', 'title', 'h1'])
            ->reject(fn (Page $p): bool => PageCategorizer::inTemplateSection((string) ($p->path ?: parse_url((string) $p->url, PHP_URL_PATH)), $bulk))
            ->each(function (Page $page) use (&$names): void {
                foreach (array_unique(LocationOptions::strip(trim($page->title.' '.$page->h1))['removed']) as $name) {
                    $names[$name] = ($names[$name] ?? 0) + 1;
                }
            });
        $cities = [];
        foreach ($names as $name => $count) {
            foreach (LocationOptions::describe((string) $name) as $reading) {
                if ($reading['kind'] === 'city' && $reading['country_code'] === 'TR') {
                    $cities[LocationOptions::fold((string) $reading['city'])] = (string) $reading['city'];
                }
            }
        }
        $out = [];
        foreach ($names as $name => $count) {
            if ($count < self::MIN_PAGES) {
                continue;
            }
            $readings = array_values(array_filter(LocationOptions::describe((string) $name), fn (array $r): bool => $r['country_code'] === 'TR' && $r['kind'] !== 'country'));
            $districts = array_values(array_filter($readings, fn (array $r): bool => $r['kind'] === 'district'));
            $city = array_values(array_filter($readings, fn (array $r): bool => $r['kind'] === 'city'))[0] ?? null;
            $named = array_values(array_filter($districts, fn (array $r): bool => isset($cities[LocationOptions::fold((string) $r['city'])])));
            $reading = $city ?? ($named[0] ?? (count($districts) === 1 ? $districts[0] : null));
            if ($reading === null || $reading['city'] === null) {
                continue;
            }
            $key = LocationOptions::fold($reading['city'].'|'.($reading['district'] ?? ''));
            $out[$key] = ['city' => (string) $reading['city'], 'district' => $reading['district'] !== null ? (string) $reading['district'] : null,
                'label' => $reading['district'] !== null ? $reading['district'].' / '.$reading['city'] : (string) $reading['city'], 'pages' => ($out[$key]['pages'] ?? 0) + $count];
        }
        // A province is only listed on its own when none of its districts is.
        $withDistrict = array_flip(array_map(fn (array $a): string => LocationOptions::fold($a['city']), array_filter($out, fn (array $a): bool => $a['district'] !== null)));
        $out = array_filter($out, fn (array $a): bool => $a['district'] !== null || ! isset($withDistrict[LocationOptions::fold($a['city'])]));
        usort($out, fn (array $a, array $b): int => [$b['pages'], $a['label']] <=> [$a['pages'], $b['label']]);

        return array_slice(array_values($out), 0, self::MAX);
    }
}
