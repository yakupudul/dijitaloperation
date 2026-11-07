<?php

namespace App\Services\Work;

use App\Models\DigitalAsset;
use App\Services\Brand\BrandScope;
use App\Services\SeoTasks\SeoText;
use App\Support\SchemaState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Search Console numbers of a few pages over the last days: impressions, clicks and the impression-weighted average
 * position (the daily page rows keep Google's position in their metadata). Keyed by SeoText::urlKey.
 */
final class PageSearchStats
{
    /**
     * @param  list<string>  $urls
     * @return array<string, array{impressions: int, clicks: int, position: ?float}>
     */
    public static function forUrls(array $urls, int $days, ?DigitalAsset $site = null): array
    {
        if ($urls === [] || ! SchemaState::hasTable('gsc_page_daily')) {
            return [];
        }
        $variants = collect($urls)->flatMap(fn (string $u): array => [$u, rtrim($u, '/'), rtrim($u, '/').'/'])->unique()->values()->all();
        $sums = [];
        foreach (array_chunk($variants, 500) as $chunk) {
            self::scope(DB::table('gsc_page_daily'), $site)->whereIn('page', $chunk)->where('reporting_date', '>=', now()->subDays($days)->toDateString())
                ->get(['page', 'impressions', 'clicks', 'metadata'])->each(function (object $row) use (&$sums): void {
                    $key = SeoText::urlKey((string) $row->page);
                    $meta = is_array($row->metadata) ? $row->metadata : (json_decode((string) ($row->metadata ?? ''), true) ?: []);
                    $position = is_numeric($meta['provider_average_position'] ?? null) ? (float) $meta['provider_average_position'] : null;
                    $sums[$key] ??= ['impressions' => 0, 'clicks' => 0, 'weighted' => 0.0, 'weight' => 0];
                    $sums[$key]['impressions'] += (int) $row->impressions;
                    $sums[$key]['clicks'] += (int) $row->clicks;
                    if ($position !== null && (int) $row->impressions > 0) {
                        $sums[$key]['weighted'] += $position * (int) $row->impressions;
                        $sums[$key]['weight'] += (int) $row->impressions;
                    }
                });
        }

        return array_map(fn (array $s): array => ['impressions' => $s['impressions'], 'clicks' => $s['clicks'],
            'position' => $s['weight'] > 0 ? round($s['weighted'] / $s['weight'], 1) : null], $sums);
    }

    /**
     * Limits a page-fact query to the site's Search Console properties (central rows) and its own legacy rows, so the
     * read walks the property's index instead of every site's pages (İçerik: 728 ms × 10 sites, admin audit 2026-11-07).
     */
    public static function scope(Builder $query, ?DigitalAsset $site): Builder
    {
        if ($site === null) {
            return $query;
        }
        $resources = BrandScope::siteResources($site, 'search_console');

        return $query->where(fn (Builder $q) => $q->whereIn('external_resource_id', $resources ?: [0])
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('external_resource_id')->where('digital_asset_id', $site->id)));
    }
}
