<?php

namespace App\Services\MetaAds;

use App\Services\MetaAds\Support\MetaAdsBindingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Creative fatigue read model (local Data Pool only, never Meta Graph).
 *
 * Per ad, over the 28 days ending at the selected period end, the first 7 and
 * the last 7 delivery days of the ad's active window are compared. Frequency in
 * meta_ad_daily is a DAILY value (metadata.frequency or impressions / daily
 * reach); a window frequency is the impression-weighted average of daily
 * values, never a de-duplicated weekly frequency.
 */
final class MetaAdsCreativeFatigueReadService
{
    public const int WINDOW_DAYS = 28;

    public const int COMPARE_DAYS = 7;

    public const float CTR_DROP = 0.30;

    public const float CTR_DROP_HIGH = 0.50;

    /** Same daily-average threshold as the Meta Ads advisor fatigue rule. */
    public const float FREQUENCY_MIN = 1.8;

    /** Same daily-average threshold as the Meta Ads advisor saturation rule. */
    public const float FREQUENCY_HIGH = 2.5;

    public const int MIN_WINDOW_IMPRESSIONS = 500;

    private const string AD_DAILY = 'meta_ad_daily';

    public function __construct(
        private readonly MetaAdsSpecialistBindingResolver $bindingResolver,
        private readonly MetaAdsUiDatasetGate $gate,
    ) {}

    /**
     * @param  array<string, string>  $adNames  ad id => display name
     * @return array{
     *     state: string,
     *     window_start: ?string,
     *     window_end: ?string,
     *     evaluated: int,
     *     insufficient: int,
     *     flagged: int,
     *     rows: list<array{
     *         ad_id: string,
     *         name: string,
     *         status: string,
     *         severity: int,
     *         first_start: string,
     *         last_end: string,
     *         first_ctr: ?float,
     *         last_ctr: ?float,
     *         ctr_change: ?float,
     *         first_frequency: ?float,
     *         last_frequency: ?float,
     *         first_spend: float,
     *         last_spend: float,
     *         currency: ?string
     *     }>
     * }
     */
    public function analyse(string $assetId, ?string $periodEnd, array $adNames = []): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        $empty = ['state' => 'unavailable', 'window_start' => null, 'window_end' => null, 'evaluated' => 0, 'insufficient' => 0, 'flagged' => 0, 'rows' => []];

        if ($binding->mode !== MetaAdsBindingMode::RealBound
            || $binding->digitalAssetId === null
            || $binding->externalResourceId === null
            || $binding->accountId === null
            || ! Schema::hasTable(self::AD_DAILY)
        ) {
            return $empty;
        }

        $timezone = $binding->timezone ?? 'UTC';
        $end = filled($periodEnd)
            ? CarbonImmutable::parse((string) $periodEnd, $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->subDay()->startOfDay();
        $start = $end->subDays(self::WINDOW_DAYS - 1);
        $empty['window_start'] = $start->toDateString();
        $empty['window_end'] = $end->toDateString();

        try {
            $readiness = $this->gate->evaluate(
                $binding->digitalAssetId,
                $binding->externalResourceId,
                self::AD_DAILY,
                $start->toDateString(),
                $end->toDateString(),
                $timezone,
            );
        } catch (Throwable) {
            return $empty;
        }

        if (! $readiness->isUsable()) {
            return $empty;
        }

        $daily = DB::table(self::AD_DAILY)
            ->where('digital_asset_id', $binding->digitalAssetId)
            ->where('external_resource_id', $binding->externalResourceId)
            ->where('account_id', $binding->accountId)
            ->whereBetween('reporting_date', [$readiness->effectiveStart ?? $start->toDateString(), $readiness->effectiveEnd ?? $end->toDateString()])
            ->orderBy('reporting_date')
            ->get(['ad_id', 'reporting_date', 'spend', 'impressions', 'clicks', 'reach', 'currency', 'metadata'])
            ->groupBy(static fn ($row): string => (string) $row->ad_id);

        if ($daily->isEmpty()) {
            return ['state' => 'no_rows'] + $empty;
        }

        $rows = [];
        $insufficient = 0;
        foreach ($daily as $adId => $adRows) {
            $active = $adRows
                ->filter(static fn ($row): bool => (int) $row->impressions > 0)
                ->map(fn ($row): array => $this->day($row))
                ->values()
                ->all();

            $row = $this->compare((string) $adId, $adNames[(string) $adId] ?? null, $active);
            if ($row === null) {
                $insufficient++;

                continue;
            }

            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            $severity = $b['severity'] <=> $a['severity'];
            if ($severity !== 0) {
                return $severity;
            }

            return ($a['ctr_change'] ?? 0.0) <=> ($b['ctr_change'] ?? 0.0);
        });

        return [
            'state' => 'available',
            'window_start' => $empty['window_start'],
            'window_end' => $empty['window_end'],
            'evaluated' => count($rows),
            'insufficient' => $insufficient,
            'flagged' => count(array_filter($rows, static fn (array $row): bool => $row['severity'] >= 2)),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array{date: string, spend: float, impressions: int, clicks: int, frequency: ?float, currency: ?string}>  $days
     * @return array<string, mixed>|null
     */
    private function compare(string $adId, ?string $name, array $days): ?array
    {
        if (count($days) < self::COMPARE_DAYS * 2) {
            return null;
        }

        $first = $this->summarise(array_slice($days, 0, self::COMPARE_DAYS));
        $last = $this->summarise(array_slice($days, -self::COMPARE_DAYS));

        if ($first['impressions'] < self::MIN_WINDOW_IMPRESSIONS || $last['impressions'] < self::MIN_WINDOW_IMPRESSIONS) {
            return null;
        }

        $firstCtr = $first['clicks'] / $first['impressions'] * 100;
        $lastCtr = $last['clicks'] / $last['impressions'] * 100;
        $ctrChange = $firstCtr > 0 ? ($lastCtr - $firstCtr) / $firstCtr : null;
        $ctrDropped = $ctrChange !== null && -$ctrChange >= self::CTR_DROP;

        $frequencyKnown = $first['frequency'] !== null && $last['frequency'] !== null;
        $frequencyRose = $frequencyKnown && $last['frequency'] > $first['frequency'];
        $frequencyHigh = $last['frequency'] !== null && $last['frequency'] >= self::FREQUENCY_MIN;

        [$severity, $status] = match (true) {
            $ctrDropped && ($frequencyRose || $frequencyHigh) && (-$ctrChange >= self::CTR_DROP_HIGH || ($last['frequency'] ?? 0) >= self::FREQUENCY_HIGH) => [3, 'fatigue_high'],
            $ctrDropped && ($frequencyRose || $frequencyHigh) => [2, 'fatigue'],
            $ctrDropped => [1, 'watch'],
            default => [0, 'healthy'],
        };

        return [
            'ad_id' => $adId,
            'name' => $name ?? ('Reklam '.$adId),
            'status' => $status,
            'severity' => $severity,
            'first_start' => $days[0]['date'],
            'last_end' => $days[count($days) - 1]['date'],
            'first_ctr' => round($firstCtr, 2),
            'last_ctr' => round($lastCtr, 2),
            'ctr_change' => $ctrChange !== null ? round($ctrChange * 100, 1) : null,
            'first_frequency' => $first['frequency'] !== null ? round($first['frequency'], 2) : null,
            'last_frequency' => $last['frequency'] !== null ? round($last['frequency'], 2) : null,
            'first_spend' => round($first['spend'], 2),
            'last_spend' => round($last['spend'], 2),
            'currency' => $days[0]['currency'],
        ];
    }

    /**
     * @param  list<array{date: string, spend: float, impressions: int, clicks: int, frequency: ?float, currency: ?string}>  $days
     * @return array{spend: float, impressions: int, clicks: int, frequency: ?float}
     */
    private function summarise(array $days): array
    {
        $sum = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'frequency' => null];
        $weighted = 0.0;
        $weight = 0;

        foreach ($days as $day) {
            $sum['spend'] += $day['spend'];
            $sum['impressions'] += $day['impressions'];
            $sum['clicks'] += $day['clicks'];
            if ($day['frequency'] !== null) {
                $weighted += $day['frequency'] * $day['impressions'];
                $weight += $day['impressions'];
            }
        }

        $sum['frequency'] = $weight > 0 ? $weighted / $weight : null;

        return $sum;
    }

    /** @return array{date: string, spend: float, impressions: int, clicks: int, frequency: ?float, currency: ?string} */
    private function day(object $row): array
    {
        $meta = is_array($row->metadata) ? $row->metadata : (json_decode((string) $row->metadata, true) ?: []);
        $impressions = (int) $row->impressions;
        $reach = $row->reach !== null ? (int) $row->reach : 0;
        $frequency = is_numeric($meta['frequency'] ?? null)
            ? (float) $meta['frequency']
            : ($reach > 0 ? $impressions / $reach : null);

        return [
            'date' => substr((string) $row->reporting_date, 0, 10),
            'spend' => (float) $row->spend,
            'impressions' => $impressions,
            'clicks' => (int) $row->clicks,
            'frequency' => $frequency,
            'currency' => $row->currency !== null ? (string) $row->currency : null,
        ];
    }
}
