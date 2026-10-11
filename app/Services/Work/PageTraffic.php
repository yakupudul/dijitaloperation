<?php

namespace App\Services\Work;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Repair\SiteAudit;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteMetrics;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The stake of page-bound work: each page's Search Console impressions and clicks over the last SiteAudit::IMPRESSION_DAYS
 * days up to the last collected day (the same window the title / description audit uses), read from the stored facts.
 * null for a page whose site has no Search Console data: its gain is unknown, not zero.
 */
final class PageTraffic
{
    /** Per-site totals are cached this long (facts arrive once a day). */
    private const int TTL_SECONDS = 600;

    public function __construct(private readonly SiteMetrics $metrics) {}

    /**
     * @param  iterable<int|string|null>  $pageIds
     * @return array<int, array{impressions: int, clicks: int}|null>
     */
    public function forPages(iterable $pageIds): array
    {
        $ids = collect($pageIds)->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (Page::query()->whereIn('id', $ids->all())->get(['id', 'website_asset_id', 'url'])->groupBy('website_asset_id') as $siteId => $pages) {
            $totals = $this->siteTotals((int) $siteId);
            foreach ($pages as $page) {
                $row = $totals === null ? null : ($totals[SeoText::urlKey((string) $page->url)] ?? null);
                $out[(int) $page->id] = $totals === null ? null : ['impressions' => (int) ($row['impressions'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0)];
            }
        }

        return $out;
    }

    /** @return array<string, array{impressions: int, clicks: int}>|null */
    private function siteTotals(int $siteId): ?array
    {
        try {
            return Cache::remember('work:page-traffic:'.$siteId, self::TTL_SECONDS, function () use ($siteId): ?array {
                $site = DigitalAsset::query()->with('brand')->find($siteId);
                $last = $site?->brand !== null ? $this->metrics->lastGscDay($site->brand) : null;
                if ($last === null) {
                    return null;
                }
                $range = [$last->subDays(SiteAudit::IMPRESSION_DAYS - 1)->toDateString(), $last->toDateString()];

                return array_map(fn (array $r): array => ['impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks']],
                    $this->metrics->pageTotals($site->brand, $site, $range));
            });
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }
}
