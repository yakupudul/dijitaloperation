<?php

namespace App\Services\Site;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brand-side context of one website: its brand, whether AI may run (operational brand), approved services, service
 * areas, bound Search Console / GA4 accounts. Read-only helpers shared by the website screen services.
 */
final class SiteScope
{
    public static function brandOf(DigitalAsset $site): ?Brand
    {
        return $site->brand_id !== null ? Brand::query()->with('customer')->find($site->brand_id) : null;
    }

    /** AI and paid calls run only for operational brands (active customer). */
    public static function aiAllowed(?Brand $brand): bool
    {
        return $brand !== null && $brand->isOperational();
    }

    /**
     * Approved (active) brand services, main priority first.
     *
     * @return Collection<int, BrandOffering>
     */
    public static function offerings(Brand $brand): Collection
    {
        return BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)
            ->where('status', OfferingStatus::Active->value)->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->orderBy('id')->get();
    }

    /**
     * Clusters of the brand's services (its sector) that nobody approved yet: shown with an "Onayla" button on the site's
     * İçerik fikirleri, so the operator does not have to find them in the shared library.
     *
     * @return Collection<int, Cluster>
     */
    public static function pendingClusters(Brand $brand, int $limit = 100): Collection
    {
        $serviceIds = BrandOffering::query()->where('brand_id', $brand->id)->where('status', OfferingStatus::Active->value)
            ->whereNotNull('service_catalog_item_id')->distinct()->pluck('service_catalog_item_id')->all();

        return Cluster::query()->with(['service.primaryName', 'mainQuery'])->withCount('clusterQueries')->where('approved', false)
            ->whereIn('service_id', $serviceIds ?: [0])->when($brand->sector_id !== null, fn ($q) => $q->where('sector_id', $brand->sector_id))
            ->orderBy('service_id')->orderBy('name')->limit($limit)->get();
    }

    /**
     * Why the site's cluster views (İçerik fikirleri, Rakipler, Öneriler, ana hizmet sayfaları) are empty, from one
     * source for every screen: active services, services tied to the catalog, their clusters and the approved ones,
     * and the brand rows (Eşleştir). `step` is the first missing one: services | catalog | clusters | approve | match | ready.
     *
     * @return array{services: int, linked: int, clusters: int, approved: int, rows: int, step: string, sector_id: ?int}
     */
    public static function clusterReadiness(Brand $brand, ?DigitalAsset $site = null): array
    {
        $offerings = BrandOffering::query()->where('brand_id', $brand->id)->where('status', OfferingStatus::Active->value);
        $services = (clone $offerings)->count();
        $serviceIds = (clone $offerings)->whereNotNull('service_catalog_item_id')->distinct()->pluck('service_catalog_item_id');
        $clusters = Cluster::query()->whereIn('service_id', $serviceIds->all() ?: [0])
            ->when($brand->sector_id !== null, fn ($q) => $q->where('sector_id', $brand->sector_id));
        $total = (clone $clusters)->count();
        $approved = (clone $clusters)->where('approved', true)->count();
        $rows = $site !== null ? BrandClusterPage::query()->where('website_asset_id', $site->id)->count()
            : BrandClusterPage::query()->where('brand_id', $brand->id)->count();
        $step = match (true) {
            $services === 0 => 'services',
            $serviceIds->isEmpty() => 'catalog',
            $total === 0 => 'clusters',
            $approved === 0 => 'approve',
            $rows === 0 => 'match',
            default => 'ready',
        };

        return ['services' => $services, 'linked' => $serviceIds->count(), 'clusters' => $total, 'approved' => $approved, 'rows' => $rows, 'step' => $step,
            'sector_id' => $brand->sector_id !== null ? (int) $brand->sector_id : null];
    }

    /**
     * Active service areas, physical branches first.
     *
     * @return Collection<int, BrandServiceArea>
     */
    public static function areas(Brand $brand): Collection
    {
        return BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByDesc('physical_branch')->orderBy('priority_rank')->orderBy('id')->get();
    }

    /** The brand's target area for commercial / local target queries (first physical branch, else first area). */
    public static function targetArea(Brand $brand): ?BrandServiceArea
    {
        return self::areas($brand)->first();
    }

    /** "ankara" / "çankaya": the place word put in front of a commercial / local main query. */
    public static function areaWord(?BrandServiceArea $area): string
    {
        if ($area === null) {
            return '';
        }
        $word = (string) ($area->city_name ?: $area->district_name ?: $area->name);

        return mb_strtolower(strtr(trim($word), ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    /**
     * Folded words of the brand's own areas (name, city, district): location pages mention them.
     *
     * @return list<string>
     */
    public static function areaWords(Brand $brand): array
    {
        $words = [];
        foreach (self::areas($brand) as $area) {
            foreach ([$area->name, $area->city_name, $area->district_name] as $value) {
                foreach (SeoText::tokens((string) $value) as $token) {
                    if (mb_strlen($token) >= 3 && ! in_array($token, ['sube', 'subesi', 'turkiye', 'merkez'], true)) {
                        $words[] = $token;
                    }
                }
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * Accounts of the given type (search_console | ga4) bound to any asset of the brand.
     *
     * @return list<int>
     */
    public static function resourceIds(Brand $brand, string $type): array
    {
        return DB::table('core_asset_bindings as b')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->join('core_external_resources as r', 'r.id', '=', 'b.external_resource_id')
            ->where('a.brand_id', $brand->id)->where('b.status', 'active')->whereNull('a.deleted_at')->where('r.resource_type', $type)
            ->distinct()->orderBy('b.external_resource_id')->pluck('b.external_resource_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Most common page language of the site (null when pages carry none). */
    public static function primaryLanguage(DigitalAsset $site): ?string
    {
        $row = Page::query()->where('website_asset_id', $site->id)->whereNotNull('language')
            ->groupBy('language')->selectRaw('language, count(*) as n')->orderByDesc('n')->first();

        return $row?->language;
    }

    /** @return list<string> languages of the site's pages */
    public static function languages(DigitalAsset $site): array
    {
        return Page::query()->where('website_asset_id', $site->id)->whereNotNull('language')->distinct()->orderBy('language')
            ->pluck('language')->map(fn ($l): string => (string) $l)->all();
    }

    public static function origin(DigitalAsset $site): string
    {
        $url = (string) ($site->primary_url ?: 'https://'.$site->domain);

        return SeoText::origin($url);
    }
}
