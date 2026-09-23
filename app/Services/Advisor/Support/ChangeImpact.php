<?php

namespace App\Services\Advisor\Support;

/**
 * Before / after comparison around change-history dates, per campaign (pure). Shared by the ad channels.
 * A change is a candidate when cost per result rose by at least the configured share, or results stopped
 * while spend continued. Timing is correlation, never proof; items say so.
 */
final class ChangeImpact
{
    /**
     * @param  list<array<string, mixed>>  $changes  each with campaign_id and date (Y-m-d); other keys are kept as events
     * @param  array<string, array<string, array{cost: float, clicks: int, conversions: float}>>  $campaignDaily
     * @param  array<string, string>  $campaignNames
     * @param  array<string, mixed>  $cfg  window_days, min_days_after, cpa_increase, min_conversions_before, min_cost_before
     * @return list<array<string, mixed>> strongest first, one per campaign
     */
    public static function candidates(array $changes, array $campaignDaily, array $campaignNames, string $end, array $cfg): array
    {
        $window = (int) ($cfg['window_days'] ?? 14);
        $byCampaignDate = [];
        foreach ($changes as $change) {
            if (($change['campaign_id'] ?? null) === null) {
                continue;
            }
            $byCampaignDate[$change['campaign_id']][$change['date']][] = $change;
        }
        $candidates = [];
        foreach ($byCampaignDate as $campaignId => $dates) {
            $daily = $campaignDaily[$campaignId] ?? [];
            $best = null;
            foreach ($dates as $date => $events) {
                $daysAfter = (int) floor((strtotime($end) - strtotime($date)) / 86400);
                if ($daysAfter < (int) ($cfg['min_days_after'] ?? 7)) {
                    continue;
                }
                $before = self::sumRange($daily, date('Y-m-d', strtotime($date.' -'.$window.' days')), date('Y-m-d', strtotime($date.' -1 day')));
                $afterEnd = min(strtotime($date.' +'.$window.' days'), strtotime($end));
                $after = self::sumRange($daily, date('Y-m-d', strtotime($date.' +1 day')), date('Y-m-d', $afterEnd));
                if ($before['days'] < $window / 2 || $after['days'] < 5 || $before['conversions'] < (float) ($cfg['min_conversions_before'] ?? 5) || $before['cost'] < (float) ($cfg['min_cost_before'] ?? 200)) {
                    continue;
                }
                $cpaBefore = $before['cost'] / $before['conversions'];
                if ($after['conversions'] <= 0) {
                    if ($after['cost'] / $after['days'] < ($before['cost'] / $before['days']) * 0.5) {
                        continue;
                    }
                    $increase = 1.0;
                    $cpaAfter = null;
                } else {
                    $cpaAfter = $after['cost'] / $after['conversions'];
                    $increase = $cpaAfter / $cpaBefore - 1;
                }
                if ($increase < (float) ($cfg['cpa_increase'] ?? 0.3)) {
                    continue;
                }
                if ($best === null || $increase > $best['increase']) {
                    $best = [
                        'campaign_id' => (string) $campaignId, 'campaign' => $campaignNames[$campaignId] ?? ('Kampanya '.$campaignId), 'date' => $date, 'increase' => $increase,
                        'cpa_before' => round($cpaBefore, 2), 'cpa_after' => $cpaAfter !== null ? round($cpaAfter, 2) : null,
                        'before' => $before, 'after' => $after, 'events' => array_slice($events, 0, 8),
                        'extra_cost' => $cpaAfter !== null ? max(0.0, ($cpaAfter - $cpaBefore) * $after['conversions']) : $after['cost'],
                    ];
                }
            }
            if ($best !== null) {
                $candidates[] = $best;
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $b['extra_cost'] <=> $a['extra_cost']);

        return $candidates;
    }

    /** @return array{cost: float, conversions: float, clicks: int, days: int} */
    public static function sumRange(array $daily, string $from, string $to): array
    {
        $sum = ['cost' => 0.0, 'conversions' => 0.0, 'clicks' => 0, 'days' => 0];
        foreach ($daily as $date => $metrics) {
            if ($date >= $from && $date <= $to) {
                $sum['cost'] += $metrics['cost'];
                $sum['conversions'] += $metrics['conversions'];
                $sum['clicks'] += $metrics['clicks'];
                $sum['days']++;
            }
        }
        $sum['cost'] = round($sum['cost'], 2);

        return $sum;
    }
}
