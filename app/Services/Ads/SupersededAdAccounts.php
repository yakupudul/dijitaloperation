<?php

namespace App\Services\Ads;

use App\Models\DigitalAsset;
use App\Services\Measurement\BrandMeasurementScope;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Eski hesap (panel audit, first round 6, approved by yakup 2026-10-21): OPC and Burcu Kısa each had a Google Ads account
 * whose data stopped ("erişim sorunu", red every day) next to a same-named account that keeps reporting; Decco's
 * "Decco Perde" never reported while "Yeni Reklam Hesabı" works. An account of a brand that has had no data for GAP_DAYS
 * while another Google Ads account of the same brand reported within FRESH_DAYS is the old account: it raises no alarm,
 * its open work is quiet (DormantAdWork) and it does not turn the brand red. Nothing is disconnected or deleted; once
 * it reports again, or the working one stops, it counts again.
 */
final class SupersededAdAccounts
{
    /** The working copy reported within these days … */
    public const int FRESH_DAYS = 3;

    /** … and the old account has had no data for at least this many days. */
    public const int GAP_DAYS = 14;

    private const string CACHE_KEY = 'ads.superseded-accounts';

    /** @return array<int, int> old account asset id => working asset id (read once per request, cached 10 minutes) */
    public static function map(): array
    {
        return once(fn (): array => app()->runningUnitTests() ? self::read() : Cache::remember(self::CACHE_KEY, 600, fn (): array => self::read()));
    }

    public static function isSuperseded(int $assetId): bool
    {
        return isset(self::map()[$assetId]);
    }

    /** @return array<int, int> */
    private static function read(): array
    {
        if (! SchemaState::hasTable('google_ads_account_daily')) {
            return [];
        }
        try {
            $assets = DigitalAsset::query()->operational()->where('type', 'google_ads')->get(['digital_assets.id', 'digital_assets.brand_id', 'digital_assets.name']);
            $groups = $assets->groupBy('brand_id')->filter(fn ($g): bool => $g->count() > 1);
            $from = CarbonImmutable::today()->subDays(400);
            $to = CarbonImmutable::today();
            $out = [];
            foreach ($groups as $group) {
                $last = $group->mapWithKeys(fn (DigitalAsset $a): array => [(int) $a->id => BrandMeasurementScope::forAssetIds([(int) $a->id])
                    ->rows('google_ads_account_daily', $from, $to)->max('reporting_date')]);
                $working = $last->filter()->sortDesc()->keys()->first();
                $workingDay = $working !== null ? CarbonImmutable::parse(substr((string) $last[$working], 0, 10)) : null;
                if ($workingDay === null || $workingDay->lt(CarbonImmutable::today()->subDays(self::FRESH_DAYS))) {
                    continue;
                }
                foreach ($last as $assetId => $day) {
                    if ($assetId !== $working && ($day === null || CarbonImmutable::parse(substr((string) $day, 0, 10))->lte(CarbonImmutable::today()->subDays(self::GAP_DAYS)))) {
                        $out[(int) $assetId] = (int) $working;
                    }
                }
            }

            return $out;
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
