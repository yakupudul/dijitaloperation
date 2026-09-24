<?php

namespace App\Services\Intel;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\SearchDemandCompetitor;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;

/**
 * Competitor watch (Faz 8e): once a week, each approved competitor's home page head (title, H1, description) and
 * sitemap URLs are read from the public web and compared with last week — new pages, removed pages and a changed
 * message. Free (no provider), read-only, safe fetcher.
 */
final class CompetitorSiteWatch
{
    private const int MAX_URLS = 5000;

    private const int MAX_CHILD_SITEMAPS = 6;

    public function __construct(private readonly PublicPageReader $reader) {}

    /** Meta Ad Library link: the page's ads when the page id is known, otherwise a keyword search (Türkiye). */
    public static function adLibraryUrl(?string $pageId, string $name): string
    {
        $base = 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=TR&media_type=all';

        return filled($pageId) && preg_match('/^\d{5,25}$/', (string) $pageId) === 1
            ? $base.'&view_all_page_id='.$pageId.'&search_type=page'
            : $base.'&q='.rawurlencode($name).'&search_type=keyword_unordered';
    }

    /** @return array{watched: int, changed: int} */
    public function runDue(): array
    {
        $stats = ['watched' => 0, 'changed' => 0];
        $brands = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))
            ->whereIn('id', SearchDemandCompetitor::query()->where('status', 'approved')->select('brand_id'))->get();
        foreach ($brands as $brand) {
            $result = $this->watchBrand($brand);
            $stats['watched'] += $result['watched'];
            $stats['changed'] += $result['changed'];
        }

        return $stats;
    }

    /** @return array{watched: int, changed: int} */
    public function watchBrand(Brand $brand, bool $force = false): array
    {
        $stats = ['watched' => 0, 'changed' => 0];
        $competitors = SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('status', 'approved')
            ->whereNotNull('normalized_domain')->orderBy('id')->limit((int) config('moxdop-intel.watch.max_competitors', 10))->get();
        foreach ($competitors as $competitor) {
            $last = DB::table('competitor_site_snapshots')->where('brand_id', $brand->id)->where('domain', $competitor->normalized_domain)->orderByDesc('observed_on')->orderByDesc('id')->first();
            if (! $force && $last !== null && now()->diffInDays($last->observed_on, true) < 6) {
                continue;
            }
            $snapshot = $this->snapshot($brand, $competitor, $last);
            $stats['watched']++;
            $stats['changed'] += ($snapshot['new_urls'] !== [] || $snapshot['changes'] !== []) ? 1 : 0;
        }

        return $stats;
    }

    /** @return array{new_urls: list<string>, changes: array<string, array{before: ?string, after: ?string}>} */
    public function snapshot(Brand $brand, SearchDemandCompetitor $competitor, ?object $last): array
    {
        $domain = (string) $competitor->normalized_domain;
        $home = $this->reader->fetch('https://'.$domain.'/');
        $head = $home['ok'] ? $this->head((string) $home['body']) : ['title' => null, 'h1' => null, 'description' => null];
        $urls = $this->sitemapUrls($domain, $home['final_url'] ?? null);

        $previous = $last !== null ? (array) json_decode((string) $last->sitemap_urls, true) : null;
        $new = $previous !== null && $urls !== null ? array_values(array_diff($urls, $previous)) : [];
        $removed = $previous !== null && $urls !== null ? count(array_diff($previous, $urls)) : 0;
        $changes = [];
        if ($last !== null && $home['ok']) {
            foreach (['title', 'h1', 'description'] as $field) {
                $before = $last->{$field};
                if ($before !== null && trim((string) $before) !== trim((string) $head[$field])) {
                    $changes[$field] = ['before' => $before, 'after' => $head[$field]];
                }
            }
        }
        DB::table('competitor_site_snapshots')->insert([
            'brand_id' => $brand->id, 'search_demand_competitor_id' => $competitor->id, 'domain' => $domain, 'observed_on' => now()->toDateString(),
            'status' => $home['ok'] ? 'ok' : 'failed',
            'title' => $head['title'] !== null ? mb_substr($head['title'], 0, 300) : null,
            'h1' => $head['h1'] !== null ? mb_substr($head['h1'], 0, 300) : null,
            'description' => $head['description'] !== null ? mb_substr($head['description'], 0, 500) : null,
            'sitemap_urls_count' => $urls !== null ? count($urls) : null,
            'sitemap_urls' => $urls !== null ? json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($last->sitemap_urls ?? null),
            'new_urls' => json_encode(array_slice($new, 0, 100), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'removed_urls_count' => $removed,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Only the latest URL list is needed for the next diff.
        if ($last !== null) {
            DB::table('competitor_site_snapshots')->where('brand_id', $brand->id)->where('domain', $domain)->where('id', '<=', $last->id)->update(['sitemap_urls' => null]);
        }

        return ['new_urls' => $new, 'changes' => $changes];
    }

    /** @return array{title: ?string, h1: ?string, description: ?string} */
    private function head(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $text = static function (string $query) use ($xpath): ?string {
            $node = $xpath->query($query)?->item(0);
            $value = $node !== null ? trim(preg_replace('/\s+/u', ' ', $node->nodeName === 'meta' ? (string) $node->getAttribute('content') : $node->textContent) ?? '') : '';

            return $value !== '' ? $value : null;
        };

        return [
            'title' => $text('//title'),
            'h1' => $text('//h1'),
            'description' => $text('//meta[translate(@name,"DESCRIPTION","description")="description"]'),
        ];
    }

    /** @return list<string>|null null when no sitemap could be read */
    private function sitemapUrls(string $domain, ?string $finalUrl): ?array
    {
        $origin = $finalUrl !== null && parse_url($finalUrl, PHP_URL_HOST) !== null ? parse_url($finalUrl, PHP_URL_SCHEME).'://'.parse_url($finalUrl, PHP_URL_HOST) : 'https://'.$domain;
        $candidates = [];
        $robots = $this->reader->fetch($origin.'/robots.txt');
        if ($robots['ok']) {
            preg_match_all('/^\s*sitemap:\s*(\S+)/mi', (string) $robots['body'], $matches);
            $candidates = $matches[1];
        }
        $candidates = array_values(array_unique(array_merge($candidates, [$origin.'/sitemap.xml', $origin.'/sitemap_index.xml'])));
        foreach ($candidates as $url) {
            $xml = $this->reader->fetch($url);
            if (! $xml['ok'] || ! str_contains((string) $xml['body'], '<loc')) {
                continue;
            }
            $urls = [];
            $body = (string) $xml['body'];
            if (str_contains($body, '<sitemapindex')) {
                foreach (array_slice($this->locs($body), 0, self::MAX_CHILD_SITEMAPS) as $child) {
                    $page = $this->reader->fetch($child);
                    if ($page['ok']) {
                        $urls = array_merge($urls, $this->locs((string) $page['body']));
                    }
                }
            } else {
                $urls = $this->locs($body);
            }
            $urls = array_values(array_unique(array_slice($urls, 0, self::MAX_URLS)));
            sort($urls);

            return $urls;
        }

        return null;
    }

    /** @return list<string> */
    private function locs(string $xml): array
    {
        preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]]+?)\s*(?:\]\]>)?\s*</loc>#i', $xml, $matches);

        return array_map(static fn (string $u): string => html_entity_decode(trim($u)), $matches[1]);
    }
}
