<?php

namespace App\Services\Collection\Providers\GoogleAds;

use Illuminate\Support\Facades\DB;

/**
 * Keyword daily rows also produce keyword snapshot rows so a keyword seen only in reports still has a
 * snapshot. Those derived rows carry no quality score; they must only fill gaps, never overwrite the
 * snapshot written by the keyword_view query (which holds quality_score and its components).
 */
final class GoogleAdsKeywordSnapshotGuard
{
    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array<string, mixed>>
     */
    public function onlyMissing(array $snapshots): array
    {
        if ($snapshots === []) {
            return [];
        }
        $first = $snapshots[0];
        $existing = DB::table('google_ads_keyword_snapshot')
            ->where('customer_id', (string) $first['customer_id'])
            ->when($first['external_resource_id'] !== null, fn ($query) => $query->where('external_resource_id', $first['external_resource_id']))
            ->when($first['digital_asset_id'] === null, fn ($query) => $query->whereNull('digital_asset_id'), fn ($query) => $query->where('digital_asset_id', $first['digital_asset_id']))
            ->whereIn('criterion_id', array_values(array_unique(array_map(static fn (array $row): string => (string) $row['criterion_id'], $snapshots))))
            ->get(['ad_group_id', 'criterion_id'])
            ->mapWithKeys(static fn (object $row): array => [$row->ad_group_id."\0".$row->criterion_id => true])
            ->all();

        return array_values(array_filter($snapshots, static fn (array $row): bool => ! isset($existing[$row['ad_group_id']."\0".$row['criterion_id']])));
    }
}
