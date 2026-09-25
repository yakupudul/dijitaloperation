<?php

namespace App\Services\Website;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\SeoTasks\SeoText;
use Closure;
use Illuminate\Support\Facades\DB;
use MoxDop\Website\Discovery\DiscoveryConfig;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use Throwable;

/**
 * 1.4.1: for websites WITHOUT the WordPress Connector, checks the sitemap hourly. Page URLs whose <lastmod> moved (or
 * that are new) get a targeted crawl of just those pages, so stored HTML follows site changes without a full crawl.
 * The first check only records a baseline. Sitemap files whose own <lastmod> did not move are not downloaded again.
 */
final class SitemapChangeWatcher
{
    private const int MAX_SITEMAP_FILES = 20;

    private const int MAX_PAGES = 5000;

    private const int MAX_CRAWL = 100;

    /** @var Closure(string): array{ok: bool, body: ?string} */
    private Closure $fetch;

    public function __construct(?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? fn (string $url): array => (new PublicHttpFetcher)->fetch($url, DiscoveryConfig::MAX_COLLECTION_RESPONSE_BYTES);
    }

    /** Websites to watch: operational, no paired WordPress Connector (those send activity events instead). @return list<int> */
    public function eligibleSiteIds(): array
    {
        $withConnector = CoreConnection::query()->where('type', 'wordpress_connector')->where('enabled', true)
            ->where('config->pairing_state', 'paired')->whereNotNull('digital_asset_id')->pluck('digital_asset_id')->all();

        return DigitalAsset::query()->operational()->where('type', 'website')->whereNotIn('id', $withConnector)
            ->where(fn ($q) => $q->whereNotNull('primary_url')->orWhereNotNull('domain'))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array{status: string, changed: int, pages: int, run_id?: int} */
    public function check(DigitalAsset $site): array
    {
        $base = rtrim((string) ($site->primary_url ?: 'https://'.$site->domain), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $state = DB::table('website_sitemap_watch')->where('digital_asset_id', $site->id)->first();
        $knownFiles = $state !== null ? (array) json_decode((string) $state->sitemaps, true) : [];
        $knownPages = $state !== null ? (array) json_decode((string) $state->pages, true) : [];

        $queue = $this->roots($base);
        $files = [];
        $pages = [];
        $seen = [];
        while ($queue !== [] && count($seen) < self::MAX_SITEMAP_FILES && count($pages) < self::MAX_PAGES) {
            [$url, $lastmod] = array_shift($queue);
            if (isset($seen[$url]) || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $seen[$url] = true;
            // An unchanged child sitemap keeps its pages from last time.
            if ($lastmod !== null && ($knownFiles[$url] ?? null) === $lastmod) {
                $files[$url] = $lastmod;
                foreach ($knownPages as $page => $value) {
                    if (is_array($value) && ($value['s'] ?? null) === $url) {
                        $pages[$page] = $value;
                    }
                }

                continue;
            }
            $response = ($this->fetch)($url);
            if (($response['ok'] ?? false) !== true || ! is_string($response['body'] ?? null)) {
                continue;
            }
            $files[$url] = $lastmod ?? '';
            $xml = (string) $response['body'];
            if (preg_match('/<sitemapindex\b/i', $xml) === 1) {
                foreach ($this->entries($xml, 'sitemap') as [$loc, $mod]) {
                    if (! SeoText::isJunkSitemap($loc)) {
                        $queue[] = [$loc, $mod];
                    }
                }

                continue;
            }
            foreach ($this->entries($xml, 'url') as [$loc, $mod]) {
                if (strtolower((string) parse_url($loc, PHP_URL_HOST)) === $host && count($pages) < self::MAX_PAGES && SeoText::isCrawlablePage($loc)) {
                    $pages[$loc] = ['m' => $mod, 's' => $url];
                }
            }
        }
        if ($files === []) {
            DB::table('website_sitemap_watch')->updateOrInsert(['digital_asset_id' => $site->id], ['error' => 'Sitemap okunamadı.', 'checked_at' => now(), 'updated_at' => now(), 'created_at' => $state->created_at ?? now()]);

            return ['status' => 'no_sitemap', 'changed' => 0, 'pages' => 0];
        }

        $changed = [];
        if ($state !== null) {
            foreach ($pages as $url => $value) {
                $before = $knownPages[$url] ?? null;
                if ($before === null || (($value['m'] ?? null) !== null && ($before['m'] ?? null) !== $value['m'])) {
                    $changed[] = $url;
                }
            }
        }
        $result = ['status' => $state === null ? 'baseline' : 'checked', 'changed' => count($changed), 'pages' => count($pages)];
        $update = ['sitemaps' => json_encode($files, JSON_UNESCAPED_SLASHES), 'pages' => json_encode($pages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'page_count' => count($pages), 'error' => null, 'checked_at' => now(), 'updated_at' => now(), 'created_at' => $state->created_at ?? now()];
        // Rewrite the (large) page map only when it changed; otherwise just the check time.
        if ($state !== null && $update['pages'] === (string) $state->pages && $update['sitemaps'] === (string) $state->sitemaps) {
            unset($update['pages'], $update['sitemaps']);
        }
        if ($changed !== []) {
            try {
                $run = app(WebsiteCollectionOrchestrator::class)->start(asset: $site, requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL], context: [
                    'force_refresh' => true,
                    'idempotency_key' => 'sitemap-watch:'.$site->id.':'.now()->format('YmdH'),
                    'collection_intent' => 'sitemap_change_refresh',
                    'collection_intent_label' => 'Sitemap change refresh',
                    'targeted_verification' => ['version' => 1, 'urls' => array_slice($changed, 0, self::MAX_CRAWL), 'candidate_url_count' => count($changed),
                        'truncated' => count($changed) > self::MAX_CRAWL, 'issue_code' => 'SITEMAP_CHANGE', 'relation_key' => 'sitemap-watch'],
                ]);
                $update += ['last_run_id' => $run->id, 'last_changed_count' => count($changed), 'changed_at' => now()];
                $result['run_id'] = (int) $run->id;
            } catch (Throwable $exception) {
                // Pages stay as they were so the change is picked up next hour.
                unset($update['pages'], $update['sitemaps']);
                $update['error'] = mb_substr('Tarama başlatılamadı: '.class_basename($exception), 0, 300);
            }
        }
        DB::table('website_sitemap_watch')->updateOrInsert(['digital_asset_id' => $site->id], $update);

        return $result;
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function roots(string $base): array
    {
        $roots = [];
        $robots = ($this->fetch)($base.'/robots.txt');
        if (($robots['ok'] ?? false) === true && is_string($robots['body'] ?? null)) {
            preg_match_all('/^\s*sitemap\s*:\s*(\S+)\s*$/im', (string) $robots['body'], $matches);
            foreach (array_slice($matches[1] ?? [], 0, 5) as $url) {
                $roots[] = [trim($url), null];
            }
        }
        if ($roots === []) {
            foreach (DiscoveryConfig::sitemapFallbackPaths() as $path) {
                $roots[] = [$base.$path, null];
            }
        }

        return $roots;
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function entries(string $xml, string $tag): array
    {
        preg_match_all('#<'.$tag.'\b[^>]*>(.*?)</'.$tag.'>#is', $xml, $blocks);
        $out = [];
        foreach ($blocks[1] ?? [] as $block) {
            if (preg_match('#<loc>\s*([^<]+?)\s*</loc>#i', $block, $loc) !== 1) {
                continue;
            }
            $mod = preg_match('#<lastmod>\s*([^<]+?)\s*</lastmod>#i', $block, $m) === 1 ? trim($m[1]) : null;
            $out[] = [trim(html_entity_decode($loc[1], ENT_QUOTES | ENT_XML1)), $mod];
        }

        return $out;
    }
}
