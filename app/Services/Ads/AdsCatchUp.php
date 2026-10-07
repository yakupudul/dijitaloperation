<?php

namespace App\Services\Ads;

use App\Jobs\Ads\RefreshAdServiceStatsJob;
use App\Jobs\CollectMetaGeoResultsJob;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\MetaAds\MetaGeoResults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly catch-up of the once-a-day Meta / Kazananlar numbers, so a deploy or a missed morning run does not leave the
 * screens empty until the next day: the per-service rows when the last run is older than a day, the first 30 days of
 * Meta regions and breakdowns of accounts that have none (once a day per account), and today's winners snapshot once
 * the numbers are in. Read-only toward every provider.
 */
class AdsCatchUp
{
    public const string RAN_AT_KEY = 'moxdop:ads:service-stats:ran-at';

    /** The per-service rows are rebuilt when the last run is older than this. */
    public const int STALE_HOURS = 23;

    /** The winners snapshot waits this long after a rebuild (the rebuild jobs are spread over minutes). */
    public const int SNAPSHOT_AFTER_MINUTES = 60;

    /** Queue the per-service rebuild of every brand's ad accounts, websites and Business Profiles. @return int assets queued */
    public static function queueServiceStats(): int
    {
        $ids = DigitalAsset::query()->whereIn('type', ['meta_ads', 'google_ads', 'website', 'google_business_profile'])->whereNotNull('brand_id')->orderBy('id')->pluck('id');
        $ids->each(fn ($id, $index) => RefreshAdServiceStatsJob::dispatch((int) $id)->delay(now()->addSeconds(5 * $index)));
        Cache::put(self::RAN_AT_KEY, now()->toIso8601String(), now()->addDays(3));

        return $ids->count();
    }

    /** @return array{service_stats: int, breakdowns: int, snapshot: int} what was queued or stored */
    public function run(): array
    {
        $done = ['service_stats' => 0, 'breakdowns' => 0, 'snapshot' => 0];
        $ranAt = Cache::get(self::RAN_AT_KEY);
        if ($ranAt === null || CarbonImmutable::parse((string) $ranAt)->lt(now()->subHours(self::STALE_HOURS))) {
            $done['service_stats'] = self::queueServiceStats();

            return $done;
        }
        $done['breakdowns'] = $this->queueMissingBreakdowns();
        if (Winners::ready() && CarbonImmutable::parse((string) $ranAt)->lte(now()->subMinutes(self::SNAPSHOT_AFTER_MINUTES))
            && ! DB::table('ad_winner_snapshots')->where('snapshot_date', CarbonImmutable::today('Europe/Istanbul')->toDateString())->exists()) {
            $done['snapshot'] = app(Winners::class)->snapshot();
        }

        return $done;
    }

    /** Meta accounts without breakdown rows: their first 30 days, at most once a day each. */
    private function queueMissingBreakdowns(): int
    {
        if (! Schema::hasTable(MetaGeoResults::BREAKDOWN_TABLE)) {
            return 0;
        }
        $with = DB::table(MetaGeoResults::BREAKDOWN_TABLE)->distinct()->pluck('digital_asset_id')->map(fn ($id): int => (int) $id)->all();
        $queued = 0;
        DigitalAsset::query()->where('type', 'meta_ads')->whereNotNull('brand_id')
            ->whereIn('id', CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->select('digital_asset_id'))
            ->whereNotIn('id', $with ?: [0])->orderBy('id')->pluck('id')
            ->each(function ($id) use (&$queued): void {
                if (Cache::add('moxdop:ads:catch-up:breakdowns:'.$id, true, now()->addDay())) {
                    CollectMetaGeoResultsJob::dispatch((int) $id)->delay(now()->addMinutes(3 * $queued));
                    $queued++;
                }
            });

        return $queued;
    }
}
