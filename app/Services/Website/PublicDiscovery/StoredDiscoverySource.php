<?php

namespace App\Services\Website\PublicDiscovery;

use App\Models\DigitalAsset;
use App\Services\Collection\Providers\Website\WebsitePageAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MoxDop\Website\Discovery\DiscoveryConfig;
use MoxDop\Website\Discovery\PublicPageExtractor;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use Throwable;

final class StoredDiscoverySource
{
    public const int MAX_PAGES = 500;

    public const int MAX_TOTAL_BYTES = 32 * 1024 * 1024;

    public const int FRESH_DAYS = 7;

    public function __construct(
        private readonly StoredHtmlReader $reader,
        private readonly PublicPageExtractor $extractor,
        private readonly PublicUrlNormalizer $urls,
        private readonly WebsitePageAnalyzer $analyzer,
    ) {}

    /** @return array<string, mixed> */
    public function inventory(DigitalAsset $asset): array
    {
        $seed = trim((string) ($asset->primary_url ?: $asset->domain));
        if (! str_contains($seed, '://')) {
            $seed = 'https://'.$seed;
        }
        $seed = $this->urls->normalizeAbsolute($seed);
        if ($seed === null) {
            throw new \InvalidArgumentException('Web sitesi URL bilgisi geçersiz.');
        }
        $query = DB::table('website_html_snapshot')->where('digital_asset_id', $asset->id)
            ->select('*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY url ORDER BY observed_at DESC, id DESC) AS version_rank');
        $snapshots = DB::query()->fromSub($query, 'latest_html')->where('version_rank', 1)->get()
            ->filter(fn ($row) => $this->eligibleUrl($seed, $row->url))->keyBy('url');
        $aliases = $snapshots->filter(function ($row) use ($seed, $snapshots): bool {
            if (! $row->requested_url || $row->requested_url === $row->url
                || ! $this->isFresh($row->observed_at) || $row->status_code < 200 || $row->status_code >= 300
                || ! $this->urls->sameSite($seed, $row->requested_url)) {
                return false;
            }
            $direct = $snapshots->get($row->requested_url);

            return $direct === null || CarbonImmutable::parse($row->observed_at)->gt(CarbonImmutable::parse($direct->observed_at));
        })->sortBy('observed_at')->pluck('url', 'requested_url');
        $resolve = function (string $url) use ($aliases): string {
            $seen = [];
            while ($aliases->has($url) && ! isset($seen[$url]) && count($seen) < 10) {
                $seen[$url] = true;
                $url = $aliases->get($url);
            }

            return $url;
        };
        $inventory = DB::table('website_url')->where('digital_asset_id', $asset->id)->pluck('url')
            ->merge($snapshots->keys())->push($seed)->map($resolve)
            ->filter(fn ($url) => is_string($url) && $this->eligibleUrl($seed, $url))->unique()->sort()->values();
        $snapshots = $snapshots->except($aliases->keys());
        $fresh = $snapshots->filter(fn ($row) => $this->isFresh($row->observed_at) && $row->raw_ingestion_object_id !== null
            && $row->status_code >= 200 && $row->status_code < 300);
        $missing = $inventory->diff($snapshots->keys())->values();
        $stale = $snapshots->keys()->diff($fresh->keys())->values();
        $ordered = $fresh->sortBy(fn ($row) => sprintf('%d|%s', $this->pagePriority($seed, $row->url), $row->url));

        return [
            'seed_url' => $seed,
            'snapshots' => $ordered->take(self::MAX_PAGES)->values()->all(),
            'inventory_urls' => $inventory->count(), 'stored_urls' => $snapshots->count(),
            'fresh_urls' => $fresh->count(), 'missing_html_urls' => $missing->count(),
            'stale_urls' => $stale->count(), 'page_limit' => self::MAX_PAGES,
            'gap_samples' => $missing->map(fn ($url) => ['url' => $url, 'reason' => 'missing'])
                ->merge($stale->map(fn ($url) => ['url' => $url, 'reason' => 'stale']))->take(100)->values()->all(),
            'refresh_urls' => $missing->merge($stale)->unique()->take(100)->values()->all(),
            'needs_collection' => $missing->isNotEmpty() || $stale->isNotEmpty() || $fresh->isEmpty(),
            'input_fingerprint' => hash('sha256', json_encode([
                DiscoveryConfig::VERSION, $seed, $inventory->all(), $aliases->sortKeys()->all(),
                $snapshots->sortKeys()->map(fn ($row) => [$row->id, $row->html_hash, $row->raw_ingestion_object_id, $this->isFresh($row->observed_at)])->all(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array<string, mixed> */
    public function read(DigitalAsset $asset, ?array $inventory = null): array
    {
        $inventory ??= $this->inventory($asset);
        $pages = [];
        $failures = [];
        $bytes = 0;
        foreach ($inventory['snapshots'] as $snapshot) {
            try {
                $stored = $this->reader->read($asset, $snapshot->url, (int) $snapshot->id);
                if ($stored === null) {
                    $failures[] = ['url' => $snapshot->url, 'error' => 'stored_html_unreadable'];

                    continue;
                }
                $size = strlen($stored['html']);
                if ($bytes + $size > self::MAX_TOTAL_BYTES) {
                    $failures[] = ['url' => $snapshot->url, 'error' => 'read_budget_exhausted'];
                    break;
                }
                $bytes += $size;
                if (! $this->analyzer->isInventoryEligible([
                    'ok' => true, 'requested_url' => $snapshot->requested_url ?: $snapshot->url,
                    'final_url' => $snapshot->url, 'status_code' => $snapshot->status_code,
                    'content_type' => $snapshot->content_type, 'body' => $stored['html'],
                ])) {
                    $failures[] = ['url' => $snapshot->url, 'error' => 'stored_html_ineligible'];

                    continue;
                }
                $pages[] = [
                    'requested_url' => $snapshot->requested_url ?: $snapshot->url,
                    'final_url' => $snapshot->url, 'status_code' => $snapshot->status_code,
                    'content_type' => $snapshot->content_type, 'bytes' => $size,
                    'observed_at' => CarbonImmutable::parse($snapshot->observed_at)->toIso8601String(),
                    'snapshot_id' => (int) $snapshot->id,
                    'raw_ingestion_object_id' => (int) $snapshot->raw_ingestion_object_id,
                    'html_hash' => $snapshot->html_hash,
                    'extracted' => $this->extractor->extract($snapshot->url, $stored['html']),
                ];
            } catch (Throwable $exception) {
                $failures[] = ['url' => $snapshot->url, 'error' => 'stored_html_unreadable', 'error_class' => class_basename($exception)];
            }
        }
        $count = count($pages);
        $coverage = array_intersect_key($inventory, array_flip(['inventory_urls', 'stored_urls', 'fresh_urls', 'missing_html_urls', 'stale_urls', 'page_limit']));
        $coverage['inspected_pages'] = $count;
        $coverage['unreadable_pages'] = count(array_filter($failures, fn ($failure) => $failure['error'] === 'stored_html_unreadable'));
        $coverage['ineligible_pages'] = count(array_filter($failures, fn ($failure) => $failure['error'] === 'stored_html_ineligible'));
        $coverage['uninspected_pages'] = max(0, $inventory['inventory_urls'] - $count);
        $coverage['complete_site_claim'] = false;
        $coverage['gap_samples'] = collect($inventory['gap_samples'] ?? [])->merge(array_map(fn ($failure) => [
            'url' => $failure['url'], 'reason' => match ($failure['error']) {
                'stored_html_unreadable' => 'unreadable', 'stored_html_ineligible' => 'ineligible', default => 'uninspected'
            },
        ], $failures))->take(100)->values()->all();
        $coverage['oldest_observation'] = collect($pages)->min('observed_at');
        $coverage['latest_observation'] = collect($pages)->max('observed_at');

        return [
            'status' => $count === 0 ? 'failed' : ($coverage['uninspected_pages'] > 0 || $failures !== [] ? 'partial' : 'succeeded'),
            'seed_url' => $inventory['seed_url'], 'pages' => $pages, 'failures' => $failures,
            'pages_inspected' => $count, 'total_bytes' => $bytes, 'coverage' => $coverage,
            'input_fingerprint' => $inventory['input_fingerprint'],
        ];
    }

    public function isFresh(string $observedAt): bool
    {
        $time = CarbonImmutable::parse($observedAt);

        return $time->gte(now()->subDays(self::FRESH_DAYS)) && $time->lte(now()->addMinutes(5));
    }

    private function eligibleUrl(string $seed, string $url): bool
    {
        return $this->urls->normalizeAbsolute($url) !== null && $this->urls->sameSite($seed, $url)
            && ! preg_match('~\.(?:pdf|xml|txt|jpg|jpeg|png|gif|webp|svg|zip|mp4|mp3|css|js)(?:$|\?)|/(?:wp-json|wp-admin)(?:/|$)~i', $url);
    }

    private function pagePriority(string $seed, string $url): int
    {
        if (rtrim($url, '/') === rtrim($seed, '/')) {
            return 0;
        }

        return preg_match('~/(?:contact|about|iletisim|hakkimizda|hizmet|service|urun|product)~i', $url) ? 1 : 2;
    }
}
