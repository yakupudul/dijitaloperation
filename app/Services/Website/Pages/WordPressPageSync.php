<?php

namespace App\Services\Website\Pages;

use App\Models\Page;
use App\Services\Collection\Providers\Website\WebsiteDatasetExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WordPress Connector → `pages` (primary source for WordPress sites). Every published post / page in any language
 * becomes one row: URL, post id / type, Polylang language, title and meta description from the SEO plugin (Yoast,
 * Rank Math, SEOPress), where the title came from (`title_source`: seo field or post title), canonical, main content
 * text + H1–H3 from the rendered content, word count, indexability (noindex → false) and the WordPress modified time.
 * Plugin events update or delete just that page; a template (theme) change marks every page changed without refetching.
 */
final class WordPressPageSync
{
    /** Events whose object is gone from the public site. */
    public const array REMOVAL_EVENTS = ['content.deleted', 'content.trashed', 'content.unpublished'];

    public function __construct(
        private readonly PageStore $pages,
        private readonly MainContentExtractor $extractor,
    ) {}

    /**
     * Connector `content` section records (one page of the snapshot).
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<int>  $requestedIds  object ids of a changed-object refresh ([] = full inventory page)
     * @return array{created: int, updated: int, unchanged: int, deleted: int}
     */
    public function syncContent(int $siteId, array $records, array $requestedIds = []): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0];
        $seen = [];
        foreach ($records as $record) {
            $postId = (int) ($record['object_id'] ?? 0);
            $type = (string) ($record['object_type'] ?? '');
            if ($postId < 1 || $type === '' || $type === 'attachment' || in_array($type, WebsiteDatasetExecutor::NON_PAGE_CMS_TYPES, true)) {
                continue;
            }
            $seen[] = $postId;
            try {
                if (($record['status'] ?? null) !== 'publish' || ! is_string($record['permalink'] ?? null) || $record['permalink'] === '') {
                    $stats['deleted'] += $this->pages->deleteWordPressObject($siteId, $postId);

                    continue;
                }
                $fields = $this->fields($siteId, $record);
                $titleSource = $fields['title_source'];
                unset($fields['title_source']);
                $stats[$this->pages->upsert($siteId, $fields)]++;
                $this->markTitleSource($siteId, $postId, $titleSource);
            } catch (Throwable $error) {
                // One broken post must not stop the inventory.
                Log::warning('pages.wordpress.sync_failed', ['site' => $siteId, 'post' => $postId, 'error' => $error->getMessage()]);
            }
        }
        // A requested object the connector no longer returns was deleted permanently.
        foreach (array_diff($requestedIds, $seen) as $missing) {
            $stats['deleted'] += $this->pages->deleteWordPressObject($siteId, (int) $missing);
        }

        return $stats;
    }

    /**
     * Connector `seo` section records: title / description / canonical / robots of pages already stored.
     *
     * @param  list<array<string, mixed>>  $records
     */
    public function syncSeo(int $siteId, array $records): int
    {
        $updated = 0;
        foreach ($records as $record) {
            $postId = (int) ($record['object_id'] ?? 0);
            $page = $postId > 0 ? Page::query()->where('website_asset_id', $siteId)->where('wp_post_id', $postId)->first() : null;
            if ($page === null) {
                continue;
            }
            $seo = $this->seoValues($record);
            $postTitle = $seo['title'] === null ? $this->storedPostTitle($siteId, $postId) : null;
            $result = $this->pages->upsert($siteId, [
                'url' => (string) $page->url,
                'wp_post_id' => $postId,
                'wp_post_type' => $page->wp_post_type,
                'language' => $page->language,
                'title' => $seo['title'] ?? $postTitle ?? $page->title,
                'meta_description' => $seo['meta_description'],
                'canonical' => $seo['canonical'] ?? $page->url,
                'h1' => $page->h1,
                'headings' => (array) $page->headings,
                'content_text' => (string) $page->content_text,
                'word_count' => (int) $page->word_count,
                'is_indexable' => $seo['is_indexable'],
            ]);
            $updated += $result === PageStore::UPDATED ? 1 : 0;
            $this->markTitleSource($siteId, $postId, $seo['title'] !== null ? 'seo' : ($postTitle !== null ? 'post' : null));
        }

        return $updated;
    }

    /**
     * After a completed full inventory: pages of posts no longer published, and URL rows that do not come from
     * WordPress, are removed — unless the operator set a sitemap override (Ayarlar): then the sitemap keeps the extra
     * URLs (SitemapPageSync removes those that leave it).
     */
    public function pruneAfterFullInventory(int $siteId): int
    {
        $sitemapOverride = trim((string) DB::table('digital_assets')->where('id', $siteId)->value('sitemap_url')) !== '';
        $published = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $siteId)
            ->where('status', 'publish')->whereNotIn('object_type', ['attachment', ...WebsiteDatasetExecutor::NON_PAGE_CMS_TYPES])
            ->pluck('object_id')->map(fn ($id): int => (int) $id)->all();
        if ($published === []) {
            // An empty inventory is more likely a connector problem than an empty site: keep what is stored.
            return 0;
        }

        return PageStore::deleteRows($siteId, Page::query()->where('website_asset_id', $siteId)
            ->where(fn ($q) => $sitemapOverride ? $q->whereNotNull('wp_post_id')->whereNotIn('wp_post_id', $published)
                : $q->whereNull('wp_post_id')->orWhereNotIn('wp_post_id', $published)));
    }

    /**
     * Plugin events arriving for a site (before the changed-object refresh runs): removals delete the page at once,
     * a template / theme change marks every page changed. Returns what was done.
     *
     * @param  iterable<object>  $events  rows of website_connector_events
     * @return array{deleted: int, touched: int}
     */
    public function applyEvents(int $siteId, iterable $events): array
    {
        $result = ['deleted' => 0, 'touched' => 0];
        $templateChanged = false;
        foreach ($events as $event) {
            $type = (string) ($event->type ?? '');
            if (in_array($type, self::REMOVAL_EVENTS, true) && ctype_digit((string) ($event->object_id ?? ''))) {
                $result['deleted'] += $this->pages->deleteWordPressObject($siteId, (int) $event->object_id);
            }
            if (self::isTemplateChange($event)) {
                $templateChanged = true;
            }
        }
        if ($templateChanged) {
            $result['touched'] = $this->pages->touchAll($siteId);
        }

        return $result;
    }

    /** Theme switched or updated: templates changed for every page. */
    public static function isTemplateChange(object $event): bool
    {
        $type = (string) ($event->type ?? '');

        return $type === 'maintenance.theme_changed'
            || ($type === 'maintenance.update_completed' && (string) ($event->object_type ?? '') === 'theme');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function fields(int $siteId, array $record): array
    {
        $postId = (int) $record['object_id'];
        $html = (string) ($record['content_rendered'] ?? $record['content_raw'] ?? '');
        $content = $this->extractor->fromFragment($html);
        $seoRow = DB::table('website_cms_seo_snapshot')->where('digital_asset_id', $siteId)->where('object_id', (string) $postId)
            ->orderByDesc('id')->first(['seo_provider', 'seo_title', 'meta_description', 'canonical_url', 'robots']);
        $seo = $this->seoValues($seoRow !== null ? (array) $seoRow : []);
        $title = trim((string) ($record['title'] ?? ''));

        return [
            // The SEO plugin's own title, or the post title when the plugin renders a template ("%title% | Site").
            'title_source' => $seo['title'] !== null ? 'seo' : ($title !== '' ? 'post' : null),
            'url' => (string) $record['permalink'],
            'wp_post_id' => $postId,
            'wp_post_type' => mb_substr((string) $record['object_type'], 0, 64),
            'language' => is_string($record['language'] ?? null) ? $record['language'] : null,
            'title' => $seo['title'] ?? ($title !== '' ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null),
            'meta_description' => $seo['meta_description'],
            'canonical' => $seo['canonical'] ?? (string) $record['permalink'],
            // Themes print the post title as the H1 outside the content; the content's own H1 wins when present.
            'h1' => $content['h1'] ?? ($title !== '' ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null),
            'headings' => $content['headings'],
            'content_text' => $content['content_text'],
            'content_outline' => $content['content_outline'],
            'word_count' => $content['word_count'],
            'is_indexable' => $seo['is_indexable'],
            'changed_at' => is_string($record['modified_at'] ?? null) ? $record['modified_at'] : null,
        ];
    }

    /**
     * SEO plugin values. A title that still holds template variables (%%title%%, %sep%, {{…}}) is not the rendered
     * title and is ignored (the post title is used instead).
     *
     * @param  array<string, mixed>  $record
     * @return array{title: ?string, meta_description: ?string, canonical: ?string, is_indexable: bool}
     */
    private function seoValues(array $record): array
    {
        $title = trim((string) ($record['seo_title'] ?? ''));
        $description = trim((string) ($record['meta_description'] ?? ''));
        $canonical = trim((string) ($record['canonical_url'] ?? ''));
        $robots = mb_strtolower(trim((string) ($record['robots'] ?? '')));
        $provider = (string) ($record['seo_provider'] ?? '');
        $noindex = str_contains($robots, 'noindex')
            || ($provider === 'yoast' && $robots === '1')
            || ($provider === 'seopress' && $robots === 'yes');

        return [
            'title' => $title !== '' && ! str_contains($title, '%') && ! str_contains($title, '{{') ? $title : null,
            'meta_description' => $description !== '' && ! str_contains($description, '%%') && ! str_contains($description, '{{') ? $description : null,
            'canonical' => preg_match('#^https?://#i', $canonical) === 1 ? $canonical : null,
            'is_indexable' => ! $noindex,
        ];
    }

    /** Records where the stored title came from (`seo` / `post`); null leaves what is stored. */
    private function markTitleSource(int $siteId, int $postId, ?string $source): void
    {
        if ($source === null) {
            return;
        }
        DB::table('pages')->where('website_asset_id', $siteId)->where('wp_post_id', $postId)
            ->where(fn ($q) => $q->whereNull('title_source')->orWhere('title_source', '!=', $source))->update(['title_source' => $source]);
    }

    private function storedPostTitle(int $siteId, int $postId): ?string
    {
        $title = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $siteId)->where('object_id', (string) $postId)
            ->orderByDesc('id')->value('title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
