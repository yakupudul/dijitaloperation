<?php

namespace App\Support\Operator;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * An ads account whose last spend is long ago is not "stale data": the collection works, the account simply does not
 * spend — often the wrong (old / secondary) account of the customer is bound. Shared by `moxdop:diagnose` and the
 * Google Ads analyst's "Veri yok" line: "hesap 1 yıldır harcamasız — doğru hesap bağlı mı?".
 */
final class DormantAccountHint
{
    /** Accounts silent at least this long get the hint. */
    public const int MIN_DAYS = 45;

    /** Human Turkish duration: "1 yıldır", "3 aydır", "50 gündür". */
    public static function since(int $days): string
    {
        return match (true) {
            $days >= 365 => intdiv($days, 365).' yıldır',
            $days >= 60 => intdiv($days, 30).' aydır',
            default => $days.' gündür',
        };
    }

    /** The hint for an account whose last spend was on $lastSpend (Y-m-d), or null when it is recent / unknown. */
    public static function text(?string $lastSpend, ?CarbonImmutable $today = null): ?string
    {
        if ($lastSpend === null || $lastSpend === '') {
            return null;
        }
        $today ??= CarbonImmutable::today();
        $days = (int) CarbonImmutable::parse(substr($lastSpend, 0, 10))->diffInDays($today, true);
        if ($days < self::MIN_DAYS) {
            return null;
        }

        return 'hesap '.self::since($days).' harcamasız (son harcama '.substr($lastSpend, 0, 10).') — doğru hesap bağlı mı?';
    }

    /**
     * Last day with Google Ads spend of the resources (account daily, else campaign daily; resource-first rows carry
     * the external resource id).
     *
     * @param  list<int>  $resourceIds
     */
    public static function lastGoogleAdsSpend(array $resourceIds): ?string
    {
        $dates = self::lastGoogleAdsSpendByResource($resourceIds);

        return $dates === [] ? null : max($dates);
    }

    /**
     * Last day with Google Ads spend per resource, in one grouped query per table (account daily and campaign daily).
     *
     * @param  list<int>  $resourceIds
     * @return array<int, string> external resource id => Y-m-d; resources that never spent are left out
     */
    public static function lastGoogleAdsSpendByResource(array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_map('intval', $resourceIds)));
        if ($resourceIds === []) {
            return [];
        }
        $latest = [];
        foreach (['google_ads_account_daily', 'google_ads_campaign_daily'] as $table) {
            try {
                if (! Schema::hasColumn($table, 'cost_amount')) {
                    continue;
                }
                $rows = DB::table($table)->whereIn('external_resource_id', $resourceIds)->where('cost_amount', '>', 0)
                    ->groupBy('external_resource_id')->selectRaw('external_resource_id, max(reporting_date) as last_spend')->get();
            } catch (Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $date = substr((string) $row->last_spend, 0, 10);
                $id = (int) $row->external_resource_id;
                if ($date !== '' && $date > ($latest[$id] ?? '')) {
                    $latest[$id] = $date;
                }
            }
        }

        return $latest;
    }
}
