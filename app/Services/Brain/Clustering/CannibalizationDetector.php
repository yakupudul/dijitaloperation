<?php

namespace App\Services\Brain\Clustering;

use App\Models\DigitalAsset;
use App\Services\Brain\ServiceSites;
use Illuminate\Support\Facades\DB;

/**
 * Keyword cannibalization per website: two of our own URLs splitting the impressions of ONE cluster (one page's
 * topic) while both rank on the first two result pages. Several URLs ranking is normal; it is flagged only when the
 * second page takes at least MIN_SECOND_SHARE of the impressions and both average positions are within MAX_POSITION.
 * Stored data only; open rows are rebuilt each run, dismissed ones are not raised again.
 */
final class CannibalizationDetector
{
    private const float MIN_SECOND_SHARE = 0.25;

    private const float MAX_POSITION = 20.0;

    private const int MIN_IMPRESSIONS = 50;

    public function __construct(
        private readonly ServiceClusters $clusters,
        private readonly ServiceSites $sites,
    ) {}

    /** @return int number of open cannibalizations for the site */
    public function detect(DigitalAsset $site): int
    {
        $serviceIds = DB::table('brand_offerings')->where('brand_id', $site->brand_id)->where('status', 'active')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id')->map('intval')->all();
        $rows = $serviceIds !== [] ? $this->sites->gscRows($site) : [];
        $dismissed = DB::table('brain_cannibalizations')->where('digital_asset_id', $site->id)->where('status', 'dismissed')->pluck('cluster_id')->filter()->map('intval')->all();
        $found = [];
        foreach ($rows === [] ? [] : $this->clusters->all($serviceIds) as $cluster) {
            if (in_array($cluster['id'], $dismissed, true)) {
                continue;
            }
            $pages = array_values(ServiceClusters::pages($rows, $cluster['keys']));
            $total = array_sum(array_column($pages, 'impressions'));
            if (count($pages) < 2 || $total < self::MIN_IMPRESSIONS) {
                continue;
            }
            [$first, $second] = [$pages[0], $pages[1]];
            $share = $second['impressions'] / $total;
            if ($share < self::MIN_SECOND_SHARE || ($first['position'] ?? 99) > self::MAX_POSITION || ($second['position'] ?? 99) > self::MAX_POSITION) {
                continue;
            }
            $found[] = [
                'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'service_id' => $cluster['service_id'], 'cluster_id' => $cluster['id'],
                'subject' => $cluster['name'], 'impressions' => $total, 'second_share' => round($share, 4),
                'pages' => json_encode(array_map(fn (array $p): array => $p + ['share' => round($p['impressions'] / $total, 3)], array_slice($pages, 0, 4)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'open', 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::transaction(function () use ($site, $found): void {
            DB::table('brain_cannibalizations')->where('digital_asset_id', $site->id)->where('status', 'open')->delete();
            if ($found !== []) {
                DB::table('brain_cannibalizations')->insert($found);
            }
        });

        return count($found);
    }
}
