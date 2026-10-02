<?php

namespace App\Services\Collection\Providers\Website;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-level crawl state (table website_crawl_state).
 *
 * - Page cache: how many page reads the site's page cache answered (hit ratio over a rolling window) and which
 *   cache plugin it runs. A site that serves (almost) every page from its cache may be read a little faster
 *   (WebsiteCrawlPoliteness::concurrency): a cached page costs the host next to nothing.
 * - Connector page cache: whether the WordPress Connector (≥ 1.6.0) can read the cache plugin's files.
 * - Full read: when every page of the site was last read (a crawl that skipped nothing as unchanged).
 * - Source mix of the latest crawl: from the page cache files, read over HTTP, unchanged (304 / same HTML).
 */
final class WebsiteCrawlState
{
    /** Page reads needed before the hit ratio is trusted. */
    public const int MIN_SAMPLES = 6;

    /** Rolling window: older observations weigh half once this many are counted. */
    private const int WINDOW = 200;

    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * Counts the cache verdicts of fetched pages (errors are ignored; a page without any cache signal is a miss).
     *
     * @param  array<string, array<string, mixed>>  $fetches
     * @return array{hits: int, checks: int}
     */
    public function recordFetches(int $assetId, array $fetches): array
    {
        $hits = 0;
        $checks = 0;
        $plugin = null;
        foreach ($fetches as $fetch) {
            $status = (int) ($fetch['status_code'] ?? 0);
            if (! in_array($status, [200, 304], true)) {
                continue;
            }
            $checks++;
            $cache = is_array($fetch['cache'] ?? null) ? $fetch['cache'] : [];
            if (($cache['hit'] ?? null) === true) {
                $hits++;
            }
            if (is_string($cache['plugin'] ?? null) && $cache['plugin'] !== '') {
                $plugin = $cache['plugin'];
            }
        }
        if ($checks === 0 || ! $this->ready()) {
            return ['hits' => $hits, 'checks' => $checks];
        }
        $row = $this->row($assetId);
        $totalHits = (int) ($row->cache_hits ?? 0) + $hits;
        $totalChecks = (int) ($row->cache_checks ?? 0) + $checks;
        if ($totalChecks > self::WINDOW) {
            $totalHits = intdiv($totalHits, 2);
            $totalChecks = intdiv($totalChecks, 2);
        }
        $this->put($assetId, array_filter([
            'cache_hits' => $totalHits,
            'cache_checks' => $totalChecks,
            'cache_plugin' => $plugin ?? ($row->cache_plugin ?? null),
        ], static fn ($value): bool => $value !== null));

        return ['hits' => $hits, 'checks' => $checks];
    }

    /** Share of page reads the site's page cache answered, or null until MIN_SAMPLES reads were seen. */
    public function hitRatio(int $assetId): ?float
    {
        if (! $this->ready()) {
            return null;
        }
        $row = $this->row($assetId);
        $checks = (int) ($row->cache_checks ?? 0);

        return $checks >= self::MIN_SAMPLES ? round((int) $row->cache_hits / $checks, 3) : null;
    }

    /** @param array{plugin: ?string, readable: bool, reason?: ?string} $pageCache */
    public function recordPageCache(int $assetId, array $pageCache): void
    {
        if (! $this->ready()) {
            return;
        }
        $row = $this->row($assetId);
        $update = ['page_cache' => json_encode(array_merge($pageCache, ['checked_at' => now()->toIso8601String()]), JSON_THROW_ON_ERROR)];
        if (is_string($pageCache['plugin'] ?? null) && $pageCache['plugin'] !== '' && ($row->cache_plugin ?? null) === null) {
            $update['cache_plugin'] = $pageCache['plugin'];
        }
        $this->put($assetId, $update);
    }

    /**
     * Where the pages of the latest crawl came from.
     *
     * @param  array<string, int>  $mix  page_cache, fetched, not_modified, same, skipped
     */
    public function recordRun(int $assetId, int $collectionRunId, array $mix, bool $finished = false): void
    {
        if (! $this->ready()) {
            return;
        }
        $this->put($assetId, ['last_run' => json_encode(array_merge($mix, [
            'collection_run_id' => $collectionRunId,
            'finished' => $finished,
            'updated_at' => now()->toIso8601String(),
        ]), JSON_THROW_ON_ERROR)]);
    }

    public function markFullRead(int $assetId): void
    {
        if ($this->ready()) {
            $this->put($assetId, ['last_full_read_at' => now()]);
        }
    }

    public function lastFullReadAt(int $assetId): ?CarbonImmutable
    {
        if (! $this->ready()) {
            return null;
        }
        $value = $this->row($assetId)->last_full_read_at ?? null;

        return $value !== null ? CarbonImmutable::parse((string) $value) : null;
    }

    /**
     * What the collection screen shows.
     *
     * @return array{cache_plugin: ?string, hit_ratio: ?float, checks: int, page_cache: ?array<string, mixed>, last_full_read_at: ?string, last_run: ?array<string, mixed>}
     */
    public function view(int $assetId): array
    {
        $row = $this->ready() ? $this->row($assetId) : null;
        $decode = static function (mixed $value): ?array {
            $decoded = is_string($value) ? json_decode($value, true) : $value;

            return is_array($decoded) ? $decoded : null;
        };

        return [
            'cache_plugin' => $row->cache_plugin ?? null,
            'hit_ratio' => $this->hitRatio($assetId),
            'checks' => (int) ($row->cache_checks ?? 0),
            'page_cache' => $decode($row->page_cache ?? null),
            'last_full_read_at' => $row->last_full_read_at ?? null,
            'last_run' => $decode($row->last_run ?? null),
        ];
    }

    /** Operator name of a cache plugin id. */
    public static function pluginLabel(?string $plugin): ?string
    {
        return match ($plugin) {
            null, '' => null,
            'litespeed' => 'LiteSpeed Cache',
            'wp_rocket' => 'WP Rocket',
            'wp_super_cache' => 'WP Super Cache',
            'w3_total_cache' => 'W3 Total Cache',
            'wp_fastest_cache' => 'WP Fastest Cache',
            'cache_enabler' => 'Cache Enabler',
            'cloudflare_apo' => 'Cloudflare APO',
            default => $plugin,
        };
    }

    private function row(int $assetId): object
    {
        return DB::table('website_crawl_state')->where('digital_asset_id', $assetId)->first() ?? (object) [];
    }

    /** @param array<string, mixed> $values */
    private function put(int $assetId, array $values): void
    {
        $values['updated_at'] = now();
        if (DB::table('website_crawl_state')->where('digital_asset_id', $assetId)->update($values) === 0
            && DB::table('website_crawl_state')->where('digital_asset_id', $assetId)->doesntExist()) {
            DB::table('website_crawl_state')->insertOrIgnore($values + ['digital_asset_id' => $assetId, 'created_at' => now()]);
        }
    }

    private function ready(): bool
    {
        return $this->tables['website_crawl_state'] ??= Schema::hasTable('website_crawl_state');
    }
}
