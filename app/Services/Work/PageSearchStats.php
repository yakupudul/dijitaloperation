<?php

namespace App\Services\Work;

use App\Services\SeoTasks\SeoText;
use App\Support\SchemaState;
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
    public static function forUrls(array $urls, int $days): array
    {
        if ($urls === [] || ! SchemaState::hasTable('gsc_page_daily')) {
            return [];
        }
        $variants = collect($urls)->flatMap(fn (string $u): array => [$u, rtrim($u, '/'), rtrim($u, '/').'/'])->unique()->values()->all();
        $sums = [];
        foreach (array_chunk($variants, 500) as $chunk) {
            DB::table('gsc_page_daily')->whereIn('page', $chunk)->where('reporting_date', '>=', now()->subDays($days)->toDateString())
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
}
