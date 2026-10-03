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

    /** A non-service folder with this many pages is a template / archive section (articles, keyword pages), never "hizmet". */
    public const int BULK_SECTION = 40;

    /** Öğrenilmiş klasör kuralı: categorized pages a folder needs, and the share that must agree. */
    public const int LEARN_MIN = 5;

    public const float LEARN_SHARE = 0.9;

    /** Unattended runs (nightly upkeep) send at most this many unsure pages to AI; more waits for the operator. */
    public const int UNATTENDED_AI_LIMIT = 200;

    /** Tag / keyword / archive folders: never a service page. */
    public const array ARCHIVE_SECTIONS = ['kws', 'tag', 'tags', 'etiket', 'etiketler', 'kategori', 'category', 'author', 'yazar', 'arsiv', 'archive', 'page', 'search', 'ara', 'arama', 'anahtar-kelime', 'anahtar-kelimeler', 'keyword', 'keywords', 'feed', 'amp'];

    private const string KURUMSAL = '#^/(?:[a-z]{2}/)?(?:hakkimizda|hakkinda|kurumsal|about|about-us|iletisim|contact|contact-us|kvkk|kvkk-aydinlatma-metni|gizlilik|gizlilik-politikasi|privacy|privacy-policy|cerez|cerez-politikasi|cookie|cookies|cookie-policy|yasal|legal|kullanim-kosullari|terms|ekibimiz|ekip|team|doktorlarimiz|kariyer|career|careers|galeri|gallery|referanslar|basinda-biz|tesekkurler|randevu)(?:/|$|-)#';

    private const string KURUMSAL_TITLE = '/^(ana ?sayfa|home|hakkimizda|hakkinda|about|iletisim|contact|kvkk|gizlilik|cerez|ekibimiz|ekip|kariyer|galeri|referans|basinda|tesekkur|randevu)/u';

    private const string FAQ = '#^/(?:[a-z]{2}/)?(?:sss|faq|faqs|sikca-sorulan-sorular|sik-sorulan-sorular|sorular)(?:/|$|-)#';

    private const string FAQ_TITLE = '/(sikca sorulan|sik sorulan|^sss\b|\bfaq\b)/u';

    public function __construct(private readonly SiteAi $ai) {}

    /**
     * Categorizes the site's unlocked pages (only uncategorized ones when $onlyNew). $aiLimit: more unsure pages than
     * this → no AI call at all (status too_many; the operator starts it). $useAi false: rules only.
     *
     * @return array{status: string, rule: int, ai: int, unsure: int} status: ready | not_operational | no_brand | too_many | ai_* (partial)
     */
    public function categorize(DigitalAsset $site, bool $onlyNew = false, ?int $aiLimit = null, bool $useAi = true): array
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
        $bulk = self::bulkSections((int) $site->id);
        $rule = 0;
        $unsure = collect();
        foreach ($pages as $page) {
            $category = self::rule($page, $offerings, $areaWords, $bulk);
            if ($category === null) {
                $unsure->push($page);

                continue;
            }
            if ($page->category !== $category || $page->category_source !== 'rule') {
                Page::query()->whereKey($page->id)->update(['category' => $category, 'category_source' => 'rule']);
            }
            $rule++;
        }
        // Öğrenilmiş kural: a folder whose categorized pages (AI or rules) agree gives its new pages the same category
        // without AI. "hizmet" is never learned this way (a service page needs its own evidence).
        $learned = self::learnedSections((int) $site->id);
        if ($learned !== []) {
            $unsure = $unsure->reject(function (Page $page) use ($learned, &$rule): bool {
                $category = $learned[self::section((string) ($page->path ?: SeoText::urlPath((string) $page->url)))] ?? null;
                if ($category === null) {
                    return false;
                }
                Page::query()->whereKey($page->id)->where('category_locked', false)->update(['category' => $category, 'category_source' => 'rule']);
                $rule++;

                return true;
            })->values();
        }
        if ($unsure->isEmpty()) {
            return ['status' => 'ready', 'rule' => $rule, 'ai' => 0, 'unsure' => 0];
        }
        if (! $useAi) {
            return ['status' => 'ready', 'rule' => $rule, 'ai' => 0, 'unsure' => $unsure->count()];
        }
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'rule' => $rule, 'ai' => 0, 'unsure' => $unsure->count()];
        }
        if ($aiLimit !== null && $unsure->count() > $aiLimit) {
            return ['status' => 'too_many', 'rule' => $rule, 'ai' => 0, 'unsure' => $unsure->count()];
        }
        $status = 'ready';
        $ai = 0;
        $waiting = false;
        foreach ($unsure->chunk(self::AI_BATCH) as $batch) {
            [$batchStatus, $count] = $this->aiBatch($batch->values(), $offerings);
            $ai += $count;
            if ($batchStatus === 'queued') {
                // Claude (MCP): every batch is asked at once, so the re-run with the answers sees the same batches.
                $waiting = true;

                continue;
            }
            if ($batchStatus !== 'ready') {
                $status = 'ai_'.$batchStatus;
                break;
            }
        }
        if ($waiting && $status === 'ready') {
            $status = 'queued';
        }

        return ['status' => $status, 'rule' => $rule, 'ai' => $ai, 'unsure' => $unsure->count() - $ai];
    }

    /**
     * Rule category of one page, or null when the rules are unsure.
     *
     * @param  list<string>  $offerings  approved service names
     * @param  list<string>  $areaWords  folded brand area words
     * @param  list<string>  $bulkSections  the site's template / archive folders ({@see bulkSections})
     */
    public static function rule(Page $page, array $offerings, array $areaWords, array $bulkSections = []): ?string
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
        if (in_array($first, self::ARCHIVE_SECTIONS, true)) {
            return 'diger';
        }
        $name = SiteText::pageName($page);
        // Hundreds of pages in one non-service folder ("/hurda/…" articles, "/kws/izmir-…-mahallesi-hurdaci") are
        // generated content: a place word → lokasyon, otherwise an article. Never a main service page.
        if (count($segments) >= 2 && in_array($first, $bulkSections, true)) {
            return self::hasAreaWord($name, $areaWords) ? 'lokasyon' : 'blog';
        }
        foreach ($offerings as $offering) {
            if (SiteText::serviceScore($name, $offering) >= 0.99) {
                return 'hizmet';
            }
        }
        // A page under the service section is a service page even when its name carries the city ("ankara-all-on-6-implant").
        if (in_array($first, SiteUrlPattern::SERVICE_SECTIONS, true) && count($segments) >= 2) {
            return 'hizmet';
        }

        return self::hasAreaWord($name, $areaWords) ? 'lokasyon' : null;
    }

    /**
     * First folders (after a language prefix) holding at least BULK_SECTION pages that are not the service section:
     * template / archive sections of the site.
     *
     * @return list<string>
     */
    public static function bulkSections(int $siteId): array
    {
        $counts = [];
        foreach (Page::query()->where('website_asset_id', $siteId)->toBase()->get(['path', 'url']) as $page) {
            $first = self::section((string) ($page->path ?: SeoText::urlPath((string) $page->url)));
            if ($first !== null) {
                $counts[$first] = ($counts[$first] ?? 0) + 1;
            }
        }

        return array_values(array_map('strval', array_keys(array_filter($counts, fn (int $count, string $first): bool => $count >= self::BULK_SECTION
            && ! in_array($first, SiteUrlPattern::SERVICE_SECTIONS, true), ARRAY_FILTER_USE_BOTH))));
    }

    /**
     * Folders the site has already categorized consistently: at least LEARN_MIN pages with a category, LEARN_SHARE of
     * them the same (never "hizmet").
     *
     * @return array<string, string> folder => category
     */
    public static function learnedSections(int $siteId): array
    {
        $counts = [];
        foreach (Page::query()->where('website_asset_id', $siteId)->whereNotNull('category')->toBase()->get(['path', 'url', 'category']) as $page) {
            $section = self::section((string) ($page->path ?: SeoText::urlPath((string) $page->url)));
            if ($section !== null) {
                $counts[$section][(string) $page->category] = ($counts[$section][(string) $page->category] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($counts as $section => $byCategory) {
            arsort($byCategory);
            $top = (string) array_key_first($byCategory);
            $total = array_sum($byCategory);
            if ($top !== 'hizmet' && $total >= self::LEARN_MIN && $byCategory[$top] / $total >= self::LEARN_SHARE) {
                $out[(string) $section] = $top;
            }
        }

        return $out;
    }

    /** First folder of a page path below which more pages sit ("/en/kws/x" → "kws"), or null for a top-level page. */
    public static function section(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', trim(mb_strtolower($path), '/'))));
        if (preg_match('/^[a-z]{2}$/', $segments[0] ?? '') === 1 && count($segments) > 1) {
            array_shift($segments);
        }

        return count($segments) >= 2 ? $segments[0] : null;
    }

    /**
     * A page in a template / archive section (never a service page).
     *
     * @param  list<string>  $bulkSections
     */
    public static function inTemplateSection(string $path, array $bulkSections): bool
    {
        $section = self::section($path);

        return $section !== null && (in_array($section, $bulkSections, true) || in_array($section, self::ARCHIVE_SECTIONS, true));
    }

    /** @param  list<string>  $areaWords */
    private static function hasAreaWord(string $name, array $areaWords): bool
    {
        foreach ($areaWords as $word) {
            foreach (SeoText::tokens($name) as $token) {
                if (SeoText::wordMatches($token, $word)) {
                    return true;
                }
            }
        }

        return false;
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
