<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\PageCategoriesAgent;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use App\Services\SeoTasks\SiteUrlPattern;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * URL categorization (hizmet | blog | kurumsal | sss | lokasyon | diger): rules first (WordPress post → blog; about /
 * contact / legal → kurumsal; FAQ → sss; approved service names → hizmet; brand area words → lokasyon), the unsure
 * pages go to ONE batched AI call (`site.page_categories`). Operator categories are locked and never changed.
 */
final class PageCategorizer
{
    public const int AI_BATCH = 200;

    private const string KURUMSAL = '#^/(?:[a-z]{2}/)?(?:hakkimizda|hakkinda|kurumsal|about|about-us|iletisim|contact|contact-us|kvkk|kvkk-aydinlatma-metni|gizlilik|gizlilik-politikasi|privacy|privacy-policy|cerez|cerez-politikasi|cookie|cookies|cookie-policy|yasal|legal|kullanim-kosullari|terms|ekibimiz|ekip|team|doktorlarimiz|kariyer|career|careers|galeri|gallery|referanslar|basinda-biz|tesekkurler|randevu)(?:/|$|-)#';

    private const string KURUMSAL_TITLE = '/^(ana ?sayfa|home|hakkimizda|hakkinda|about|iletisim|contact|kvkk|gizlilik|cerez|ekibimiz|ekip|kariyer|galeri|referans|basinda|tesekkur|randevu)/u';

    private const string FAQ = '#^/(?:[a-z]{2}/)?(?:sss|faq|faqs|sikca-sorulan-sorular|sik-sorulan-sorular|sorular)(?:/|$|-)#';

    private const string FAQ_TITLE = '/(sikca sorulan|sik sorulan|^sss\b|\bfaq\b)/u';

    public function __construct(private readonly SiteAi $ai) {}

    /**
     * Categorizes the site's unlocked pages (only uncategorized ones when $onlyNew).
     *
     * @return array{status: string, rule: int, ai: int, unsure: int} status: ready | not_operational | no_brand | ai_* (partial)
     */
    public function categorize(DigitalAsset $site, bool $onlyNew = false): array
    {
        $brand = SiteScope::brandOf($site);
        if ($brand === null) {
            return ['status' => 'no_brand', 'rule' => 0, 'ai' => 0, 'unsure' => 0];
        }
        $offerings = SiteScope::offerings($brand)->map(fn (BrandOffering $o): string => $o->displayName())->all();
        $areaWords = SiteScope::areaWords($brand);
        $pages = Page::query()->where('website_asset_id', $site->id)->where('category_locked', false)
            ->when($onlyNew, fn ($q) => $q->whereNull('category'))
            ->orderBy('id')->get(['id', 'url', 'path', 'title', 'h1', 'wp_post_type', 'language', 'category', 'category_source']);
        $rule = 0;
        $unsure = collect();
        foreach ($pages as $page) {
            $category = self::rule($page, $offerings, $areaWords);
            if ($category === null) {
                $unsure->push($page);

                continue;
            }
            if ($page->category !== $category || $page->category_source !== 'rule') {
                Page::query()->whereKey($page->id)->update(['category' => $category, 'category_source' => 'rule']);
            }
            $rule++;
        }
        if ($unsure->isEmpty()) {
            return ['status' => 'ready', 'rule' => $rule, 'ai' => 0, 'unsure' => 0];
        }
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'rule' => $rule, 'ai' => 0, 'unsure' => $unsure->count()];
        }
        $status = 'ready';
        $ai = 0;
        foreach ($unsure->chunk(self::AI_BATCH) as $batch) {
            [$batchStatus, $count] = $this->aiBatch($batch->values(), $offerings);
            $ai += $count;
            if ($batchStatus !== 'ready') {
                $status = 'ai_'.$batchStatus;
                break;
            }
        }

        return ['status' => $status, 'rule' => $rule, 'ai' => $ai, 'unsure' => $unsure->count() - $ai];
    }

    /**
     * Rule category of one page, or null when the rules are unsure.
     *
     * @param  list<string>  $offerings  approved service names
     * @param  list<string>  $areaWords  folded brand area words
     */
    public static function rule(Page $page, array $offerings, array $areaWords): ?string
    {
        $path = '/'.ltrim(mb_strtolower((string) ($page->path ?: SeoText::urlPath((string) $page->url))), '/');
        $title = SeoText::fold((string) ($page->title ?: $page->h1));
        if ($page->wp_post_type === 'post') {
            return 'blog';
        }
        if ($path === '/' || preg_match('#^/[a-z]{2}/?$#', $path) === 1 || preg_match(self::KURUMSAL, $path) === 1 || preg_match(self::KURUMSAL_TITLE, $title) === 1) {
            return 'kurumsal';
        }
        if (preg_match(self::FAQ, $path) === 1 || preg_match(self::FAQ_TITLE, $title) === 1) {
            return 'sss';
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $first = preg_match('/^[a-z]{2}$/', $segments[0] ?? '') === 1 && count($segments) > 1 ? ($segments[1] ?? '') : ($segments[0] ?? '');
        if (in_array($first, SiteUrlPattern::POST_SECTIONS, true) && count($segments) >= 2 || preg_match('#/\d{4}/\d{2}/#', $path) === 1) {
            return 'blog';
        }
        $name = SiteText::pageName($page);
        foreach ($offerings as $offering) {
            if (SiteText::serviceScore($name, $offering) >= 0.99) {
                return 'hizmet';
            }
        }
        // A page under the service section is a service page even when its name carries the city ("ankara-all-on-6-implant").
        if (in_array($first, SiteUrlPattern::SERVICE_SECTIONS, true) && count($segments) >= 2) {
            return 'hizmet';
        }
        $tokens = SeoText::tokens($name);
        foreach ($areaWords as $word) {
            foreach ($tokens as $token) {
                if (SeoText::wordMatches($token, $word)) {
                    return 'lokasyon';
                }
            }
        }

        return null;
    }

    /** Operator's category: stored and locked (no rule or AI pass changes it). */
    public function setCategory(Page $page, string $category): void
    {
        if (! in_array($category, Page::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Geçersiz kategori.']);
        }
        $page->forceFill(['category' => $category, 'category_source' => 'manual', 'category_locked' => true])->save();
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @param  list<string>  $offerings
     * @return array{0: string, 1: int}
     */
    private function aiBatch(Collection $pages, array $offerings): array
    {
        $result = $this->ai->run(new PageCategoriesAgent, [
            'categories' => Page::CATEGORY_LABELS,
            'services' => $offerings,
            'pages' => $pages->map(fn (Page $p): array => ['id' => (int) $p->id, 'url' => (string) $p->url, 'title' => $p->title, 'h1' => $p->h1, 'wp_type' => $p->wp_post_type])->all(),
        ]);
        if ($result['status'] !== 'ready') {
            return [$result['status'], 0];
        }
        $ids = $pages->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $done = 0;
        foreach ((array) ($result['data']['pages'] ?? []) as $row) {
            $id = is_array($row) && is_int($row['page_id'] ?? null) ? $row['page_id'] : null;
            $category = is_array($row) ? ($row['category'] ?? null) : null;
            if ($id === null || ! $ids->has($id) || ! in_array($category, Page::CATEGORIES, true)) {
                continue;
            }
            // Never over a lock the operator set while the call ran.
            $done += Page::query()->whereKey($id)->where('category_locked', false)->update(['category' => $category, 'category_source' => 'ai']);
            $ids->forget($id);
        }

        return ['ready', $done];
    }
}
