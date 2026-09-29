<?php

namespace App\Services\Portfolio;

use App\Ai\Agents\BrandServiceAgent;
use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceCandidate;
use App\Models\Page;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Faz 2 "Hizmet keşfi" (Marka › Ayarlar › Hizmetler › "Sayfalardan hizmet çıkar"): the `pages` rows of the brand's
 * website(s) minus home / about / blog posts / contact / legal / category pages go to ONE AI call that proposes
 * normalized Turkish service names, the matching catalog item of the brand's sector (else a new catalog item, flagged)
 * and the source pages. Page and catalog ids are checked against the input. Re-runs only add new proposals; approved
 * offerings are locked and never renamed by AI.
 */
final class BrandServiceExtractor
{
    public const int MAX_PAGES = 300;

    private const array EXCLUDED_CATEGORIES = ['blog', 'kurumsal', 'sss'];

    private const array EXCLUDED_POST_TYPES = ['post', 'attachment', 'product_cat', 'category', 'post_tag'];

    /** First path segment (after an optional language prefix) that is never a service page. */
    private const string EXCLUDED_PATH = '#^/(?:[a-z]{2}/)?(?:blog|bloglar|haber|haberler|news|makale|makaleler|hakkimizda|hakkinda|kurumsal|about|about-us|iletisim|contact|contact-us|kvkk|gizlilik|gizlilik-politikasi|privacy|privacy-policy|cerez|cerez-politikasi|cookie|cookies|cookie-policy|yasal|legal|kullanim-kosullari|terms|category|kategori|tag|etiket|author|yazar|sss|faq|kariyer|career|careers|galeri|gallery|ekibimiz|ekip|team|doktorlarimiz|referanslar|basinda-biz|sepet|cart|hesabim|my-account|odeme|checkout|feed|search|arama)(?:/|$)#';

    /** Folded titles that are never a service. */
    private const string EXCLUDED_TITLE = '/^(ana ?sayfa|home|hakkimizda|hakkinda|about|iletisim|contact|blog|sss|sikca sorulan|faq|galeri|ekibimiz|ekip|kariyer|kvkk|gizlilik|cerez|tesekkur|referans|basinda|sepet|hesabim|odeme)/u';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly IdentityLabelNormalizer $normalizer,
        private readonly ServiceCatalogService $catalog,
        private readonly BrandOfferingService $offerings,
    ) {}

    /**
     * Service-page candidates of the brand's websites.
     *
     * @return Collection<int, Page>
     */
    public function candidatePages(Brand $brand): Collection
    {
        $siteIds = $brand->digitalAssets()->where('type', 'website')->pluck('id');

        return Page::query()->whereIn('website_asset_id', $siteIds)->where('is_indexable', true)
            ->orderBy('path')->limit(self::MAX_PAGES * 3)
            ->get(['id', 'website_asset_id', 'url', 'path', 'category', 'language', 'title', 'h1', 'wp_post_type'])
            ->reject(fn (Page $page): bool => self::isExcluded($page))
            ->take(self::MAX_PAGES)
            ->values();
    }

    public static function isExcluded(Page $page): bool
    {
        $path = '/'.ltrim(mb_strtolower((string) ($page->path ?: parse_url((string) $page->url, PHP_URL_PATH) ?: '/')), '/');
        if ($path === '/' || preg_match('#^/[a-z]{2}/?$#', $path) === 1) {
            return true;
        }
        if (in_array($page->category, self::EXCLUDED_CATEGORIES, true) || in_array($page->wp_post_type, self::EXCLUDED_POST_TYPES, true)) {
            return true;
        }
        if (preg_match(self::EXCLUDED_PATH, $path) === 1 || preg_match('#/\d{4}/\d{2}/#', $path) === 1 || preg_match('#/page/\d+#', $path) === 1) {
            return true;
        }
        $title = SeoText::fold((string) ($page->h1 ?: $page->title));

        return $title === '' || preg_match(self::EXCLUDED_TITLE, $title) === 1;
    }

    /** @return array{status: string, added: int} status: ready | not_operational | no_sector | no_pages | no_provider | error */
    public function extract(Brand $brand): array
    {
        if (! Brand::query()->operational()->whereKey($brand->id)->exists()) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $sector = $brand->sectorCategory;
        if ($sector === null) {
            return ['status' => 'no_sector', 'added' => 0];
        }
        $pages = $this->candidatePages($brand);
        if ($pages->isEmpty()) {
            return ['status' => 'no_pages', 'added' => 0];
        }
        $catalog = ServiceCatalogItem::query()->with('primaryName')->where('sector', $sector->code)->where('status', 'active')->limit(400)->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)
            ->mapWithKeys(fn (ServiceCatalogItem $item): array => [(int) $item->id => (string) $item->primaryName->raw_label]);
        $existing = BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)
            ->where('status', OfferingStatus::Active->value)->get();

        try {
            $route = $this->routes->resolve(AiRouteKeys::BRAND_SERVICES);
            if ($route->isEmpty()) {
                return ['status' => 'no_provider', 'added' => 0];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $structured = (new BrandServiceAgent)->prompt(
                "DATA_JSON\n".json_encode([
                    'brand' => ['name' => $brand->name, 'sector' => $sector->name],
                    'pages' => $pages->map(fn (Page $p): array => ['id' => (int) $p->id, 'url' => (string) $p->url, 'title' => $p->title, 'h1' => $p->h1, 'language' => $p->language])->all(),
                    'catalog' => $catalog->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name])->values()->all(),
                    'existing' => $existing->map(fn (BrandOffering $o): string => $o->displayName())->values()->all(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 180,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Brand service extraction failed.', ['brand_id' => $brand->id, 'error' => $exception->getMessage()]);

            return ['status' => 'error', 'added' => 0];
        }

        $pageIds = $pages->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $takenKeys = BrandServiceCandidate::query()->where('brand_id', $brand->id)->pluck('normalized_key')->all();
        foreach ($existing as $offering) {
            $takenKeys[] = $this->normalizer->normalize($offering->displayName());
        }
        $takenCatalog = $existing->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $added = 0;
        foreach (array_slice(is_array($structured['services'] ?? null) ? $structured['services'] : [], 0, 40) as $row) {
            $name = is_array($row) && is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $key = $this->normalizer->normalize($name);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || $key === '' || in_array($key, $takenKeys, true)) {
                continue;
            }
            $sources = array_values(array_unique(array_filter(array_map('intval', (array) ($row['page_ids'] ?? [])), fn (int $id): bool => in_array($id, $pageIds, true))));
            if ($sources === []) {
                continue; // a service no given page shows is not shown
            }
            $catalogId = is_int($row['catalog_item_id'] ?? null) && $catalog->has($row['catalog_item_id']) ? (int) $row['catalog_item_id'] : null;
            if ($catalogId !== null && in_array($catalogId, $takenCatalog, true)) {
                continue;
            }
            BrandServiceCandidate::query()->create([
                'brand_id' => $brand->id, 'name' => mb_substr($name, 0, 160), 'normalized_key' => mb_substr($key, 0, 191),
                'service_catalog_item_id' => $catalogId, 'new_catalog_item' => $catalogId === null,
                'page_ids' => $sources, 'status' => BrandServiceCandidate::PROPOSED,
            ]);
            $takenKeys[] = $key;
            if ($catalogId !== null) {
                $takenCatalog[] = $catalogId;
            }
            $added++;
        }

        return ['status' => 'ready', 'added' => $added];
    }

    /**
     * Approve (optionally renamed): links / creates the catalog item of the brand's sector and creates the locked brand
     * offering with its priority.
     */
    public function approve(BrandServiceCandidate $candidate, User $actor, ?string $name = null, string $priority = 'secondary'): BrandOffering
    {
        if ($candidate->status !== BrandServiceCandidate::PROPOSED) {
            throw ValidationException::withMessages(['candidate' => 'Bu öneri zaten karara bağlandı.']);
        }
        if (! array_key_exists($priority, BrandOffering::PRIORITIES)) {
            throw ValidationException::withMessages(['priority' => 'Öncelik ana ya da ikincil olmalı.']);
        }
        $brand = $candidate->brand;
        $name = trim((string) $name) !== '' ? trim((string) $name) : (string) $candidate->name;
        $renamed = $this->normalizer->normalize($name) !== $candidate->normalized_key;

        return DB::transaction(function () use ($candidate, $brand, $name, $renamed, $priority, $actor): BrandOffering {
            $item = ! $renamed && $candidate->service_catalog_item_id !== null ? ServiceCatalogItem::query()->find($candidate->service_catalog_item_id) : null;
            $item ??= $this->catalog->resolveOrCreate($name, $brand->sectorCategory?->code, actor: $actor)['service'];
            $offering = BrandOffering::query()->where('brand_id', $brand->id)->where('service_catalog_item_id', $item->id)->first()
                ?? $this->offerings->resolveOrCreate($brand, (string) ($item->primaryName?->raw_label ?? $name), actor: $actor)['offering'];
            $offering->forceFill(['priority' => $priority, 'locked' => true, 'status' => OfferingStatus::Active])->save();
            $candidate->forceFill([
                'name' => mb_substr($name, 0, 160), 'service_catalog_item_id' => $item->id,
                'status' => BrandServiceCandidate::APPROVED, 'brand_offering_id' => $offering->id,
            ])->save();

            return $offering;
        });
    }

    public function skip(BrandServiceCandidate $candidate): void
    {
        if ($candidate->status === BrandServiceCandidate::PROPOSED) {
            $candidate->forceFill(['status' => BrandServiceCandidate::SKIPPED])->save();
        }
    }

    /**
     * Merges proposals into the target: source pages are combined, merged ones are closed (never proposed again).
     *
     * @param  list<int>  $otherIds
     */
    public function merge(BrandServiceCandidate $target, array $otherIds): BrandServiceCandidate
    {
        $others = BrandServiceCandidate::query()->where('brand_id', $target->brand_id)->where('status', BrandServiceCandidate::PROPOSED)
            ->whereIn('id', $otherIds)->whereKeyNot($target->id)->get();
        $pages = collect((array) $target->page_ids);
        foreach ($others as $other) {
            $pages = $pages->merge((array) $other->page_ids);
            $other->forceFill(['status' => BrandServiceCandidate::SKIPPED])->save();
        }
        $target->forceFill(['page_ids' => $pages->map(fn ($id): int => (int) $id)->unique()->values()->all()])->save();

        return $target;
    }
}
