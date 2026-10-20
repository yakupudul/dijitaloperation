<?php

namespace App\Services\Alerts;

use Illuminate\Support\Collection;

/**
 * One alarm per account (owner 2026-10-20): an account whose budget ran out also "spent nothing today" and "sends no
 * data"; those are one problem. The most basic cause leads (access / billing / budget, then no spend today, then no
 * data, then the rest); the others stay as hidden detail under it.
 */
final class AlarmCauses
{
    /** Pseudo kind of an ad account without a working binding ("Hesap bağlı değil"). */
    public const string UNBOUND = 'account_unbound';

    /** Pseudo kind of a spending account whose newest row is late ("Veri / harcama gelmiyor"). */
    public const string LATE = 'account_late';

    /**
     * Cause rank per kind, lowest first. Kinds not listed rank after all of these.
     *
     * @var array<string, int>
     */
    public const array RANKS = [
        self::UNBOUND => 0, 'site_down' => 0, 'budget_account_blocked' => 0, 'budget_exhausted' => 0,
        'budget_low' => 1, 'budget_campaign_capped' => 1,
        'budget_no_spend_today' => 2, 'delivery_stopped' => 2,
        'ads_data_gap' => 3, self::LATE => 3, 'stale_data' => 3, 'ga4_stale' => 3, 'gsc_stale' => 3,
    ];

    private const int OTHER_RANK = 4;

    private const array SEVERITY_RANKS = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public static function rank(string $kind): int
    {
        return self::RANKS[$kind] ?? self::OTHER_RANK;
    }

    /**
     * The alarms of one account, most basic cause first (then the more severe, then the original order).
     *
     * @template TItem
     *
     * @param  iterable<TItem>  $alarms
     * @param  callable(TItem): string  $kind
     * @param  callable(TItem): string  $severity
     * @return Collection<int, TItem>
     */
    public static function ordered(iterable $alarms, callable $kind, callable $severity): Collection
    {
        return collect($alarms)->values()
            ->map(fn (mixed $alarm, int $index): array => [$alarm, [self::rank($kind($alarm)), self::SEVERITY_RANKS[$severity($alarm)] ?? 4, $index]])
            ->sort(fn (array $a, array $b): int => $a[1] <=> $b[1])
            ->map(fn (array $pair): mixed => $pair[0])
            ->values();
    }

    /**
     * Groups alarms per account: each group is [lead alarm, the other alarms of that account in cause order].
     * Groups keep the order of their lead alarm's first appearance.
     *
     * @template TItem
     *
     * @param  iterable<TItem>  $alarms
     * @param  callable(TItem): (int|string)  $account
     * @param  callable(TItem): string  $kind
     * @param  callable(TItem): string  $severity
     * @return Collection<int, array{lead: TItem, others: Collection<int, TItem>, all: Collection<int, TItem>}>
     */
    public static function perAccount(iterable $alarms, callable $account, callable $kind, callable $severity): Collection
    {
        return collect($alarms)->values()->groupBy(fn (mixed $alarm): string => (string) $account($alarm))
            ->map(function (Collection $group) use ($kind, $severity): array {
                $ordered = self::ordered($group, $kind, $severity);

                return ['lead' => $ordered->first(), 'others' => $ordered->slice(1)->values(), 'all' => $ordered];
            })->values();
    }
}
