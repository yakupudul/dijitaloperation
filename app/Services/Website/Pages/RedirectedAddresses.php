<?php

namespace App\Services\Website\Pages;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Support\SchemaState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Yönlendirilen adres sayfa değildir" (admin audit 2026-11-07 V2): when a site is rebuilt, its old addresses keep
 * answering with a 301 to the new pages. The crawl follows the redirect, so the old address looked healthy (the
 * target's 200) and stayed in the crawl URL list (`website_url`) and the page inventory (`pages`): moximu.com showed
 * 1,020 "Diğer" pages of the old site, all redirected, and the repair desk opened work for them. This sweep reads each
 * address's latest HTTP fetch and, when the fetch ended on another address, removes it from the crawl list (so it is
 * not fetched again) and from the inventory (open work is closed as "page gone"). A page WordPress still publishes is
 * kept in the inventory: the screen shows it as 301 instead, so the operator sees that a live post redirects away.
 */
final class RedirectedAddresses
{
    /** @return array{sites: int, urls: int, pages: int} */
    public function sweep(?int $siteId = null): array
    {
        $out = ['sites' => 0, 'urls' => 0, 'pages' => 0];
        if (! SchemaState::hasTable('website_http_snapshot')) {
            return $out;
        }
        $sites = DigitalAsset::query()->where('type', 'website')->when($siteId !== null, fn ($q) => $q->whereKey($siteId))->orderBy('id')->get(['id', 'name', 'domain']);
        foreach ($sites as $site) {
            $result = $this->sweepSite((int) $site->id);
            if ($result['urls'] + $result['pages'] > 0) {
                $out['sites']++;
                $out['urls'] += $result['urls'];
                $out['pages'] += $result['pages'];
                Log::info('Yönlendirilen adresler envanterden çıktı.', ['site' => $site->id, 'name' => $site->name] + $result);
            }
        }

        return $out;
    }

    /** @return array{urls: int, pages: int} */
    public function sweepSite(int $siteId): array
    {
        $aliases = $this->redirected($siteId);
        if ($aliases === []) {
            return ['urls' => 0, 'pages' => 0];
        }
        $urls = 0;
        if (SchemaState::hasTable('website_url')) {
            $ids = [];
            foreach (DB::table('website_url')->where('digital_asset_id', $siteId)->orderBy('id')->get(['id', 'normalized_url']) as $row) {
                if (isset($aliases[self::key((string) $row->normalized_url)])) {
                    $ids[] = (int) $row->id;
                }
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                $urls += DB::table('website_url')->whereIn('id', $chunk)->delete();
            }
        }
        // Posts WordPress still publishes stay (shown as 301); every other inventory row of a redirected address goes.
        $published = SchemaState::hasTable('website_cms_object_snapshot')
            ? DB::table('website_cms_object_snapshot')->where('digital_asset_id', $siteId)->where('status', 'publish')->pluck('object_id')->map(fn ($id): int => (int) $id)->flip()->all()
            : [];
        $pageIds = [];
        foreach (Page::query()->where('website_asset_id', $siteId)->orderBy('id')->get(['id', 'url', 'wp_post_id']) as $page) {
            if (isset($aliases[self::key((string) $page->url)]) && ($page->wp_post_id === null || ! isset($published[(int) $page->wp_post_id]))) {
                $pageIds[] = (int) $page->id;
            }
        }
        $pages = $pageIds === [] ? 0 : PageStore::deleteRows($siteId, Page::query()->whereIn('id', $pageIds));

        return ['urls' => $urls, 'pages' => $pages];
    }

    /**
     * Addresses whose latest fetch ended somewhere else, keyed by their comparison key.
     *
     * @return array<string, true>
     */
    public function redirected(int $siteId): array
    {
        $latest = DB::table('website_http_snapshot')->where('digital_asset_id', $siteId)->groupBy('url')->select('url')->selectRaw('max(observed_at) as observed_at');
        $out = [];
        DB::table('website_http_snapshot as s')->joinSub($latest, 'l', fn ($join) => $join->on('l.url', '=', 's.url')->on('l.observed_at', '=', 's.observed_at'))
            ->where('s.digital_asset_id', $siteId)->orderBy('s.url')->select(['s.url', 's.metadata'])
            ->chunk(1000, function ($rows) use (&$out): void {
                foreach ($rows as $row) {
                    $meta = is_array($row->metadata) ? $row->metadata : (json_decode((string) $row->metadata, true) ?: []);
                    if (self::redirectedElsewhere((string) $row->url, $meta)) {
                        $out[self::key((string) $row->url)] = true;
                    }
                }
            });

        return $out;
    }

    /**
     * True when the fetch of $url followed at least one redirect and ended on another address (another path or
     * another host); a redirect onto the same page (http → https, trailing slash, www) is not a move.
     *
     * @param  array<string, mixed>  $meta  the stored http snapshot metadata (final_url, redirect_count)
     */
    public static function redirectedElsewhere(string $url, array $meta): bool
    {
        $final = trim((string) ($meta['final_url'] ?? ''));
        if ($final === '' || (int) ($meta['redirect_count'] ?? 0) < 1) {
            return false;
        }

        return self::key($final) !== self::key($url);
    }

    /** "https://www.x.com/a/?utm=1" → "x.com/a": host without www, path without the trailing slash, no query. */
    public static function key(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? '/'), '/');

        return preg_replace('/^www\./', '', $host).($path === '' ? '' : $path);
    }
}
