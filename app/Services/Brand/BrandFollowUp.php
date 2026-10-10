<?php

namespace App\Services\Brand;

use App\Jobs\Ads\RefreshAdServiceStatsJob;
use App\Jobs\Brand\RefreshBrandFilesJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The brand's numbers follow its own collection, not the clock (yakup, 2026-10-10 "orkestra"): accounts are collected
 * each at its own time through the day, so once every account of a brand has finished (none collecting or queued) and
 * one of them brought new data since the last follow-up, the brand's per-service rows (Kazananlar, kart) and its brand
 * file / card are built again. The fixed-time nightly runs stay as the safety net.
 */
final class BrandFollowUp
{
    /** A collection must have finished at least this long ago (late datasets of the same run land meanwhile). */
    public const int SETTLE_MINUTES = 10;

    private const array BUSY = ['collecting', 'planning'];

    private const array STATS_ASSETS = ['meta_ads', 'google_ads', 'website', 'google_business_profile'];

    public static function stampKey(int $brandId): string
    {
        return 'moxdop:brand-follow-up:'.$brandId;
    }

    /** @return array{brands: int, followed: int, busy: int} */
    public function run(): array
    {
        $out = ['brands' => 0, 'followed' => 0, 'busy' => 0];
        foreach (Brand::query()->operational()->orderBy('id')->pluck('id') as $brandId) {
            $out['brands']++;
            $state = $this->state((int) $brandId);
            if ($state === 'busy') {
                $out['busy']++;
            } elseif ($state === 'due') {
                $this->follow((int) $brandId);
                $out['followed']++;
            }
        }

        return $out;
    }

    /** @return string due | busy | done (nothing new, or not settled yet) */
    public function state(int $brandId): string
    {
        $assets = BrandScope::assetIds($brandId);
        $rows = DB::table('core_asset_bindings as b')->join('resource_automations as a', 'a.external_resource_id', '=', 'b.external_resource_id')
            ->whereIn('b.digital_asset_id', $assets ?: [0])->where('b.status', 'active')->where('a.collection_enabled', true)
            ->get(['a.collection_status', 'a.collection_queued_at', 'a.last_collection_success_at']);
        if ($rows->contains(fn (object $r): bool => in_array((string) $r->collection_status, self::BUSY, true) || $r->collection_queued_at !== null)) {
            return 'busy';
        }
        $latest = $rows->pluck('last_collection_success_at')->filter()->map(fn ($t): CarbonImmutable => CarbonImmutable::parse((string) $t))->max();
        if ($latest === null || $latest->gt(now()->subMinutes(self::SETTLE_MINUTES))) {
            return 'done';
        }
        $stamp = Cache::get(self::stampKey($brandId));

        return $stamp === null || $latest->gt(CarbonImmutable::parse((string) $stamp)) ? 'due' : 'done';
    }

    private function follow(int $brandId): void
    {
        DigitalAsset::query()->where('brand_id', $brandId)->whereIn('type', self::STATS_ASSETS)->orderBy('id')->pluck('id')
            ->each(fn ($id, $index) => RefreshAdServiceStatsJob::dispatch((int) $id)->delay(now()->addSeconds(5 * $index)));
        RefreshBrandFilesJob::soon($brandId);
        Cache::put(self::stampKey($brandId), now()->toIso8601String(), now()->addDays(7));
    }
}
