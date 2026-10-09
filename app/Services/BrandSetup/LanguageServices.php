<?php

namespace App\Services\BrandSetup;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteScope;

/**
 * Hizmetler tek dilde (yakup, 2026-10-09: OPC's services listed "Einzelzahnimplantatbehandlung in", "Otturazione a"…):
 * a site's translated pages are versions of a service in the main language, never services of their own. Service
 * pages in another language are left out wherever services are read from the site, and services the system once made
 * only from such pages are archived (an operator-locked or main service is never touched).
 */
final class LanguageServices
{
    /** First path segments that name a language (Polylang / WPML style "/en/…"). */
    private const array LANGUAGE_SEGMENTS = ['en', 'de', 'it', 'fr', 'es', 'ru', 'ar', 'nl', 'pl', 'az', 'ka', 'uk', 'ro', 'bg', 'el', 'pt', 'sv', 'da', 'fa', 'he', 'zh', 'ja', 'ko', 'tr'];

    /** The site's main language: the most common page language, Turkish when pages carry none. */
    public static function mainLanguage(DigitalAsset $site): string
    {
        return strtolower((string) (SiteScope::primaryLanguage($site) ?? 'tr'));
    }

    /** A page in another language than the site's main one (its language, or a "/en/" style first path segment). */
    public static function foreign(?string $language, string $pathOrUrl, string $main): bool
    {
        $language = strtolower(substr((string) $language, 0, 2));
        if ($language !== '') {
            return $language !== $main;
        }
        $segment = strtolower(explode('/', trim(SeoText::urlPath($pathOrUrl), '/'))[0] ?? '');

        return in_array($segment, self::LANGUAGE_SEGMENTS, true) && $segment !== $main;
    }

    /**
     * Archives the services whose every linked page is in another language than the site's main one.
     *
     * @return int services archived
     */
    public function cleanup(?int $brandId = null): int
    {
        $archived = 0;
        Brand::query()->operational()->when($brandId !== null, fn ($q) => $q->whereKey($brandId))->orderBy('id')->each(function (Brand $brand) use (&$archived): void {
            $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get();
            if ($sites->isEmpty()) {
                return;
            }
            $mains = $sites->mapWithKeys(fn (DigitalAsset $s): array => [(int) $s->id => self::mainLanguage($s)])->all();
            $offerings = BrandOffering::query()->where('brand_id', $brand->id)->where('status', OfferingStatus::Active->value)->where('locked', false)
                ->where(fn ($q) => $q->whereNull('priority')->orWhere('priority', '!=', 'main'))->where(fn ($q) => $q->whereNull('is_priority')->orWhere('is_priority', false))->get();
            foreach ($offerings as $offering) {
                $pages = Page::query()->whereIn('id', OfferingPage::query()->where('brand_offering_id', $offering->id)->select('page_id'))
                    ->get(['id', 'website_asset_id', 'url', 'path', 'language']);
                if ($pages->isEmpty()) {
                    continue;
                }
                $allForeign = $pages->every(fn (Page $p): bool => isset($mains[(int) $p->website_asset_id])
                    && self::foreign($p->language, (string) ($p->path ?: $p->url), $mains[(int) $p->website_asset_id]));
                if ($allForeign) {
                    $offering->forceFill(['status' => OfferingStatus::Archived->value])->save();
                    OfferingPage::query()->where('brand_offering_id', $offering->id)->where('locked', false)->delete();
                    $archived++;
                }
            }
        });

        return $archived;
    }
}
