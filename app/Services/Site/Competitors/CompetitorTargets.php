<?php

namespace App\Services\Site\Competitors;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Services\Intel\SerpResults;
use Illuminate\Support\Collection;

/**
 * What the Rakipler screen searches: the brand's approved clusters (services of its active offerings), one
 * representative query each (the main query; commercial / local intent gets the brand's main target area —
 * "ankara implant merkezi" — informational stays as is) × the brand's target location, the site's language and the
 * configured device.
 */
final class CompetitorTargets
{
    public const array AREA_INTENTS = ['commercial', 'local'];

    public function __construct(private readonly SerpLocationResolver $locations) {}

    /** @return Collection<int, Cluster> */
    public function clusters(Brand $brand): Collection
    {
        $serviceIds = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')
            ->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id');
        if ($serviceIds->isEmpty()) {
            return collect();
        }

        return Cluster::query()->with('mainQuery')->where('approved', true)->whereIn('service_id', $serviceIds)
            ->when($brand->sector_id !== null, fn ($q) => $q->where('sector_id', $brand->sector_id))
            ->orderBy('service_id')->orderBy('name')->get();
    }

    /** The brand's main target area: first by priority, physical branch first, then oldest. */
    public function mainArea(Brand $brand): ?BrandServiceArea
    {
        return BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByRaw('CASE WHEN priority_rank IS NULL THEN 1 ELSE 0 END')->orderBy('priority_rank')
            ->orderByDesc('physical_branch')->orderBy('id')->first();
    }

    /** The word put in front of commercial / local queries: the area's city, else district, else its name. */
    public static function areaTerm(?BrandServiceArea $area): ?string
    {
        if ($area === null) {
            return null;
        }
        $term = SerpResults::normalize((string) ($area->city_name ?: $area->district_name ?: $area->name));

        return $term !== '' ? $term : null;
    }

    public static function representativeQuery(Cluster $cluster, ?string $areaTerm): string
    {
        $query = SerpResults::normalize((string) ($cluster->mainQuery?->text ?? $cluster->name));
        if ($areaTerm === null || ! in_array($cluster->intent, self::AREA_INTENTS, true)) {
            return $query;
        }
        if (preg_match('/(^|\s)'.preg_quote($areaTerm, '/').'/u', $query) === 1) {
            return $query;
        }

        return trim($areaTerm.' '.$query);
    }

    /** Language of the SERP: the mapped page's language, else the site's first language, else its market language, else tr. */
    public static function language(DigitalAsset $site, ?BrandClusterPage $mapping): string
    {
        $candidates = [$mapping?->language, ((array) ($site->languages ?? []))[0] ?? null, $site->seo_market_language_code];
        foreach ($candidates as $candidate) {
            $code = mb_strtolower(substr(trim((string) $candidate), 0, 2));
            if (preg_match('/^[a-z]{2}$/', $code) === 1) {
                return $code;
            }
        }

        return 'tr';
    }

    /**
     * @return list<array{cluster: Cluster, query: string, location_code: int, language_code: string, device: string}>
     */
    public function forSite(DigitalAsset $site): array
    {
        $brand = $site->brand;
        if ($brand === null) {
            return [];
        }
        $area = $this->mainArea($brand);
        $term = self::areaTerm($area);
        $location = $this->locations->forArea($area, $site);
        $device = (string) config('moxdop-site.competitors.device', 'mobile');
        $mappings = BrandClusterPage::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)->get()->keyBy('cluster_id');
        $out = [];
        foreach ($this->clusters($brand) as $cluster) {
            $query = self::representativeQuery($cluster, $term);
            if ($query === '') {
                continue;
            }
            $out[] = [
                'cluster' => $cluster, 'query' => $query, 'location_code' => $location,
                'language_code' => self::language($site, $mappings->get($cluster->id)), 'device' => $device,
            ];
        }

        return $out;
    }
}
