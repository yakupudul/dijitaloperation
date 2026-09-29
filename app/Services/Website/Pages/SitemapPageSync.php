<?php

namespace App\Services\Website\Pages;

use App\Models\Page;
use App\Services\Website\PageFetcher;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Non-WordPress sites → `pages`: the sitemap lists the URLs; each new or changed (lastmod moved) URL is fetched with
 * the safe public fetcher and its main content extracted (header / footer / nav / aside removed). A bounded number of
 * pages per pass, so the first import of a large site converges over a few hourly passes. URLs that left the sitemap
 * are removed.
 */
final class SitemapPageSync
{
    public const int MAX_FETCH_PER_PASS = 40;

    /** @var Closure(string): array{html: ?string, final_url: ?string, error: ?string} */
    private Closure $fetch;

    public function __construct(
        private readonly PageStore $pages,
        private readonly MainContentExtractor $extractor,
        ?Closure $fetch = null,
    ) {
        $this->fetch = $fetch ?? fn (string $url): array => app(PageFetcher::class)->fetch($url);
    }

    /**
     * @param  array<string, array{m?: ?string}>  $sitemapPages  url => sitemap entry (lastmod)
     * @param  list<string>  $changed  URLs whose lastmod moved since the previous pass
     * @return array{fetched: int, created: int, updated: int, unchanged: int, failed: int, removed: int}
     */
    public function sync(int $siteId, array $sitemapPages, array $changed = []): array
    {
        $stats = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'removed' => 0];
        if ($sitemapPages === []) {
            return $stats;
        }
        $rows = Page::query()->where('website_asset_id', $siteId)->get(['id', 'url_hash', 'updated_at']);
        $stored = $rows->pluck('url_hash', 'id')->all();
        $checkedAt = $rows->mapWithKeys(fn (Page $page): array => [$page->url_hash => $page->updated_at?->getTimestamp() ?? 0])->all();
        $wanted = [];
        foreach (array_keys($sitemapPages) as $url) {
            $wanted[PageStore::urlHash($url)] = $url;
        }

        // New URLs first, then those whose sitemap lastmod is newer than our last check (or reported changed).
        $queue = [];
        $later = [];
        $changedHashes = array_flip(array_map(fn (string $url): string => PageStore::urlHash($url), $changed));
        foreach ($wanted as $hash => $url) {
            if (! isset($checkedAt[$hash])) {
                $queue[] = $url;

                continue;
            }
            $lastmod = strtotime((string) ($sitemapPages[$url]['m'] ?? ''));
            if (isset($changedHashes[$hash]) || ($lastmod !== false && $lastmod > $checkedAt[$hash])) {
                $later[] = $url;
            }
        }
        $queue = [...$queue, ...$later];

        foreach (array_slice($queue, 0, self::MAX_FETCH_PER_PASS) as $url) {
            try {
                $response = ($this->fetch)($url);
                $stats['fetched']++;
                if (! is_string($response['html'] ?? null) || $response['html'] === '') {
                    $stats['failed']++;

                    continue;
                }
                $content = $this->extractor->fromDocument($response['html'], $url);
                $result = $this->pages->upsert($siteId, ['url' => $url, 'wp_post_id' => null] + $content);
                $stats[$result]++;
                if ($result === PageStore::UNCHANGED) {
                    // Checked now: the same lastmod does not trigger another fetch.
                    Page::query()->where('website_asset_id', $siteId)->where('url_hash', PageStore::urlHash($url))->update(['updated_at' => now()]);
                }
            } catch (Throwable $error) {
                $stats['failed']++;
                Log::warning('pages.sitemap.fetch_failed', ['site' => $siteId, 'url' => $url, 'error' => $error->getMessage()]);
            }
        }

        // Rows whose URL left the sitemap (not WordPress rows; those follow the connector).
        $gone = array_keys(array_filter($stored, fn (string $hash): bool => ! isset($wanted[$hash])));
        if ($gone !== []) {
            $stats['removed'] = Page::query()->where('website_asset_id', $siteId)->whereNull('wp_post_id')->whereIn('id', $gone)->delete();
        }

        return $stats;
    }
}
