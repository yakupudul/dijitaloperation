<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;

/**
 * Küme haritası (yakup, 2026-10-15 "kümenin hakkı veriliyor mu"): which page of a site owns a topic, so a new article
 * never competes with a page the site already has or with another article of the same subject.
 *
 *  - Sahip sayfa: a main-language page of the site whose title, H1 or address carries every topic word of the idea's
 *    search (at least two), or whose title says the same thing as the idea's title. A new idea on such a topic becomes
 *    that page's update instead (opcklinik.com: "Kanal tedavisi nasıl yapılır" was written next to
 *    /kanal-tedavisi-nasil-yapiliyor-adim-adim-surec/).
 *  - Kardeş fikir: another content idea of the site (waiting, approved or sent) with the same subject (avrupadent.com.tr
 *    got two "All-on-4 mü All-on-6 mı" articles the same night).
 *  - Harita: for the writer, the cluster's main page (ana sayfa) and the site's other pages and articles of the cluster,
 *    so the article answers only its own question and links to them instead of repeating them.
 */
final class ContentMap
{
    /** Words that carry no topic (question and filler words, Turkish and English). */
    private const array FILLER = ['ne', 'neden', 'nasil', 'nedir', 'nelerdir', 'neler', 'kac', 'hangi', 'hangisi', 'mi', 'mu', 'midir', 'mudur', 'misiniz',
        'icin', 'ile', 've', 'veya', 'ya', 'da', 'de', 'gibi', 'olan', 'olur', 'olmali', 'var', 'yok', 'gerekir', 'gerekli', 'zaman', 'kadar', 'sonra', 'once',
        'bir', 'bu', 'su', 'en', 'cok', 'daha', 'her', 'tum', 'hakkinda', 'bilinmesi', 'gerekenler', 'rehber', 'rehberi', 'merak', 'edilenler', 'size', 'sizin',
        'what', 'how', 'why', 'when', 'which', 'can', 'does', 'do', 'is', 'are', 'the', 'and', 'for', 'with', 'you', 'your', 'of', 'to', 'in', 'an', 'it'];

    /** First letters a word is compared by (Turkish suffixes: "tedavisi", "tedavisinde" → "tedav"). */
    private const int STEM = 5;

    /** Two titles say the same thing from this share of shared topic words (Dice). */
    private const float SAME_TITLE = 0.8;

    /** @var array<int, Collection<int, Page>> */
    private array $pages = [];

    /** @return list<string> the topic stems of a text */
    public static function stems(string $text): array
    {
        $words = preg_split('/\s+/u', SeoText::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(fn (string $w): string => mb_substr($w, 0, self::STEM),
            array_filter($words, fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::FILLER, true) && ! ctype_digit($w)))));
    }

    /** Whether two titles name the same subject (shared topic words, Dice). */
    public static function sameSubject(string $a, string $b): bool
    {
        $x = self::stems($a);
        $y = self::stems($b);
        if (count($x) < 2 || count($y) < 2) {
            return false;
        }
        $shared = count(array_intersect($x, $y));

        return $shared >= 2 && (2 * $shared) / (count($x) + count($y)) >= self::SAME_TITLE;
    }

    /**
     * Whether the page is about the search: its title, H1 or address carries every topic word of the search (two at
     * least) and its title adds little else (one word for a two-word search, three for a longer one), so "Kanal
     * tedavisinde iğne yapılır mı?" does not own "kanal tedavisi".
     */
    public static function covers(Page $page, string $query): bool
    {
        $needles = self::stems($query);
        if (count($needles) < 2) {
            return false;
        }
        $own = self::stems(implode(' ', [(string) $page->title, (string) $page->h1, str_replace(['-', '/'], ' ', SeoText::urlPath((string) $page->url))]));
        $extra = array_diff(self::stems((string) ($page->title ?: $page->h1)), $needles);

        return array_diff($needles, $own) === [] && count($extra) <= (count($needles) >= 3 ? 3 : 1);
    }

    /**
     * The site's page that already answers this subject, or null.
     *
     * @param  Collection<int, Page>|null  $pages  defaults to the site's main-language content pages
     */
    public function owner(DigitalAsset $site, ?string $query, string $title, ?Collection $pages = null): ?Page
    {
        $pages ??= $this->pages($site);
        $query = trim((string) $query);

        return $pages->first(fn (Page $p): bool => $query !== '' && self::covers($p, $query))
            ?? $pages->first(fn (Page $p): bool => self::sameSubject($title, (string) ($p->title ?: $p->h1)));
    }

    /** Another content idea of the site with the same subject (waiting, approved, written or sent), or null. */
    public function sibling(DigitalAsset $site, ?string $query, string $title, ?int $exceptId = null): ?Suggestion
    {
        $folded = SeoText::fold(trim((string) $query));

        return Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', SiteSuggestionTypes::CONTENT)->where('action->site_id', (int) $site->id)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED, Suggestion::APPLIED])
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->orderBy('id')
            // Only the fields compared: the rows' actions carry whole articles.
            ->get(['id', 'title', 'status', 'action->kind as idea_kind', 'action->query as idea_query'])
            ->first(fn (Suggestion $s): bool => ($s->getAttribute('idea_kind') ?? 'new') !== 'update' && (self::sameSubject($title, (string) $s->title)
                || ($folded !== '' && mb_strlen($folded) >= 6 && SeoText::fold((string) $s->getAttribute('idea_query')) === $folded)));
    }

    /** The page the cluster's main search points to on this site (its ana sayfa), or null. */
    public function pillar(Brand $brand, DigitalAsset $site, Cluster $cluster): ?Page
    {
        $row = BrandClusterPage::query()->where('brand_id', $brand->id)->where('cluster_id', $cluster->id)->where('website_asset_id', $site->id)->whereNotNull('page_id')
            ->orderByRaw('CASE WHEN language = ? THEN 0 ELSE 1 END', [SiteScope::primaryLanguage($site) ?? ''])->orderBy('id')->first();

        return $row !== null ? Page::query()->find($row->page_id, Page::LIST_COLUMNS) : null;
    }

    /**
     * The cluster map for the writer: its main page and the site's other pages / articles of the cluster (8 at most).
     *
     * @return array{main_page?: array{url: string, title: ?string}, other_pages?: list<array{url: string, title: ?string}>}
     */
    public function forWriter(Brand $brand, DigitalAsset $site, Cluster $cluster, Suggestion $suggestion): array
    {
        $target = SeoText::urlKey((string) data_get($suggestion->action, 'target_url', ''));
        $own = $suggestion->page_id !== null ? (int) $suggestion->page_id : null;
        $pillar = $this->pillar($brand, $site, $cluster);
        $pillar = $pillar !== null && (int) $pillar->id !== $own && SeoText::urlKey((string) $pillar->url) !== $target ? $pillar : null;
        $pageIds = BrandClusterPage::query()->where('brand_id', $brand->id)->where('cluster_id', $cluster->id)->where('website_asset_id', $site->id)->get()
            ->flatMap(fn (BrandClusterPage $row): array => [...$row->pageIds(), ...array_map('intval', (array) $row->overlap_page_ids)])->unique()
            ->reject(fn (int $id): bool => $id === $own || $id === $pillar?->id)->values()->all();
        $main = SiteScope::primaryLanguage($site);
        $others = Page::query()->whereIn('id', $pageIds ?: [0])->get(['id', 'url', 'title', 'language', 'path'])
            ->filter(fn (Page $p): bool => ContentPlanner::inLanguage($p, $main))
            ->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values();
        $articles = Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)->where('cluster_id', $cluster->id)
            ->where('id', '!=', $suggestion->id)->whereIn('status', [Suggestion::APPROVED, Suggestion::APPLIED])->whereNotNull('action->article_write_id')
            ->orderBy('id')->get(['id', 'title', 'action'])
            ->map(fn (Suggestion $s): array => ['url' => (string) data_get($s->action, 'target_url', ''), 'title' => $s->title])
            ->filter(fn (array $a): bool => $a['url'] !== '');

        return array_filter([
            'main_page' => $pillar !== null ? ['url' => (string) $pillar->url, 'title' => $pillar->title] : null,
            'other_pages' => $others->merge($articles)->unique('url')->take(8)->values()->all(),
        ], fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * Turns an unsent new idea that a page of the site already answers into that page's update, or closes it when the
     * page (or another idea) already carries the subject. The article and its check are dropped; the idea's date
     * (otopilot) is freed for another subject.
     *
     * @return array{outcome: string, url?: string}|null null when the idea owns its subject
     */
    public function resolve(Suggestion $suggestion): ?array
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        if ($site === null || ($action['kind'] ?? 'new') === 'update' || isset($action['article_write_id'])) {
            return null;
        }
        $query = is_string($action['query'] ?? null) ? $action['query'] : null;
        $page = $this->owner($site, $query, (string) $suggestion->title);
        if ($page !== null) {
            $busy = Suggestion::query()->where('brand_id', $suggestion->brand_id)->where('action_type', SiteSuggestionTypes::CONTENT)->where('page_id', $page->id)
                ->where('id', '!=', $suggestion->id)->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED])->exists();
            if ($busy) {
                $this->close($suggestion, 'Sitede bu konuyu işleyen sayfa var ('.SeoText::urlPath((string) $page->url).') ve onun için bekleyen bir güncelleme fikri var.');

                return ['outcome' => 'closed', 'url' => (string) $page->url];
            }
            unset($action['article'], $action['translations'], $action['translations_blocked'], $action['article_seo'], $action['article_warnings'],
                $action['article_note'], $action['autopilot'], $action['article_format']);
            $suggestion->forceFill(['page_id' => $page->id, 'target_type' => 'page', 'target_id' => $page->id, 'status' => Suggestion::OPEN, 'resolved_by' => null, 'resolved_at' => null,
                'action' => array_merge($action, ['kind' => 'update', 'target_url' => (string) $page->url, 'angle' => 'update',
                    'map_note' => 'Sitede bu konuyu işleyen sayfa vardı; yeni yazı yerine o sayfa güçlendirilecek.'])])->save();

            return ['outcome' => 'update', 'url' => (string) $page->url];
        }
        $sibling = $this->sibling($site, $query, (string) $suggestion->title, (int) $suggestion->id);
        if ($sibling !== null && $sibling->id < $suggestion->id) {
            $this->close($suggestion, 'Aynı konuda başka bir yazı fikri var: «'.$sibling->title.'».');

            return ['outcome' => 'closed'];
        }

        return null;
    }

    private function close(Suggestion $suggestion, string $note): void
    {
        $action = (array) $suggestion->action;
        unset($action['autopilot']);
        $suggestion->forceFill(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'operator_note' => mb_substr($note, 0, 240), 'action' => $action])->save();
    }

    /** @return Collection<int, Page> the site's indexable main-language content pages (no home, archive or corporate page) */
    private function pages(DigitalAsset $site): Collection
    {
        $main = SiteScope::primaryLanguage($site);

        return $this->pages[(int) $site->id] ??= Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'kurumsal'))->orderBy('id')
            ->get(['id', 'url', 'path', 'title', 'h1', 'category', 'language', 'wp_post_type'])
            ->filter(fn (Page $p): bool => trim(SeoText::urlPath((string) $p->url), '/') !== '' && ! self::archive((string) $p->url)
                && ContentPlanner::inLanguage($p, $main))->values();
    }

    private static function archive(string $url): bool
    {
        $first = explode('/', trim(SeoText::urlPath($url), '/'))[0];

        return in_array(strtolower($first), PageCategorizer::ARCHIVE_SECTIONS, true);
    }
}
