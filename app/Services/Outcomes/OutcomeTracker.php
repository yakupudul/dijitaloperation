<?php

namespace App\Services\Outcomes;

use App\Models\Suggestion;
use App\Models\User;
use App\Services\Outcomes\Readers\GoogleAdsOutcomeReader;
use App\Services\Outcomes\Readers\MapsOutcomeReader;
use App\Services\Outcomes\Readers\MetaOutcomeReader;
use App\Services\Outcomes\Readers\SearchOutcomeReader;
use App\Services\Site\BrandMemoryService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Sonuç takibi (Faz 9) — the ONE outcome mechanism of the suggestions table.
 *
 * Baseline (on apply): the channel's numbers of the 28 days before apply (ending on the last collected day, at most
 * the day before apply), stored as `{<metric>: value, …, window_days: 28, from, to, captured_at, scope: {…}}`.
 *
 * Measurement (`moxdop:outcomes:measure`, daily): `d28` = days 1–28 after apply, `d56` = days 29–56, each stored in
 * `outcome` with its verdict once the window is over and collected (waits at most WAIT_DAYS for late data).
 *
 * Verdict rule (only here): the channel's primary metric (RULES) is compared with the baseline.
 *  - belirsiz: scope / data missing, window not fully collected, or the volume metric below its minimum in both periods;
 *  - işe yaradı: the primary metric improved (in its better direction) by at least MIN_CHANGE (10 %);
 *  - işe yaramadı: otherwise.
 */
final class OutcomeTracker
{
    public const string TZ = 'Europe/Istanbul';

    public const int WINDOW_DAYS = 28;

    /** Point => number of 28-day windows after apply before it starts. */
    public const array POINTS = ['d28' => 0, 'd56' => 1];

    /** Days to wait after a window's end for its data to be collected; then it is measured as it is (belirsiz). */
    public const int WAIT_DAYS = 14;

    public const float MIN_CHANGE = 0.10;

    public const string WORKED = 'worked';

    public const string NOT_WORKED = 'not_worked';

    public const string UNCLEAR = 'unclear';

    public const array VERDICT_LABELS = [self::WORKED => 'işe yaradı', self::NOT_WORKED => 'işe yaramadı', self::UNCLEAR => 'belirsiz'];

    /** channel => metric reader (one small class per channel). */
    public const array READERS = [
        'search' => SearchOutcomeReader::class,
        'maps' => MapsOutcomeReader::class,
        'google_ads' => GoogleAdsOutcomeReader::class,
        'meta' => MetaOutcomeReader::class,
    ];

    /** channel => primary metric (decides), volume metric and its minimum (below it in both periods = belirsiz). */
    public const array RULES = [
        'search' => ['primary' => 'clicks', 'volume' => 'clicks', 'min' => 20],
        'maps' => ['primary' => 'actions', 'volume' => 'actions', 'min' => 20],
        'google_ads' => ['primary' => 'cpa', 'volume' => 'conversions', 'min' => 5],
        'meta' => ['primary' => 'cpr', 'volume' => 'results', 'min' => 10],
    ];

    /** Metrics where lower is better; every other metric is better when higher. */
    public const array LOWER_IS_BETTER = ['position', 'cpa', 'cpr'];

    public const array METRIC_LABELS = [
        'clicks' => 'tık', 'impressions' => 'gösterim', 'position' => 'sıra', 'actions' => 'etkileşim', 'views' => 'görüntülenme',
        'calls' => 'arama', 'directions' => 'yol tarifi', 'website_clicks' => 'site tıklaması', 'cost' => 'maliyet', 'conversions' => 'dönüşüm',
        'cpa' => 'dönüşüm başı maliyet', 'spend' => 'harcama', 'results' => 'sonuç', 'cpr' => 'sonuç başı maliyet', 'ctr' => 'TO %',
    ];

    public function reader(string $channel): ?OutcomeMetricReader
    {
        $class = self::READERS[$channel] ?? null;

        return $class !== null ? app($class) : null;
    }

    /**
     * Apply: status applied, applied_at now, previous outcome cleared, baseline stored (plus $extra, e.g. the write id).
     *
     * @param  array<string, mixed>  $extra
     */
    public function apply(Suggestion $suggestion, ?User $user, array $extra = []): void
    {
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'applied_at' => now(), 'resolved_at' => now(), 'resolved_by' => $user?->id ?? $suggestion->resolved_by,
            'outcome' => null, 'measured_at' => null]);
        $suggestion->forceFill(['baseline' => $this->baseline($suggestion) + $extra])->save();
        app(BrandMemoryService::class)->recordOutcome($suggestion);
    }

    /**
     * The 28 days before apply (applied_at, else now), ending on the last collected day.
     *
     * @return array<string, mixed>
     */
    public function baseline(Suggestion $suggestion): array
    {
        $applied = self::day($suggestion->applied_at ?? now());
        $shape = ['window_days' => self::WINDOW_DAYS, 'captured_at' => now()->toIso8601String(), 'scope' => null];
        $reader = $this->reader((string) $suggestion->channel);
        if ($reader === null) {
            return $shape;
        }
        try {
            $scope = $reader->scope($suggestion);
            if ($scope === null) {
                return $shape;
            }
            $shape['scope'] = $scope;
            $last = $reader->lastDay($scope);
            if ($last === null) {
                return $shape;
            }
            $end = min($last, $applied->subDay()->toDateString());
            $from = CarbonImmutable::parse($end)->subDays(self::WINDOW_DAYS - 1)->toDateString();

            return ($reader->read($scope, $from, $end) ?? []) + $shape + ['from' => $from, 'to' => $end];
        } catch (Throwable $error) {
            report($error);

            return $shape;
        }
    }

    /**
     * Measures the due points (d28, d56) of an applied suggestion; each point is measured once.
     *
     * @return list<string> points measured now
     */
    public function measure(Suggestion $suggestion, ?CarbonImmutable $today = null): array
    {
        if ($suggestion->status !== Suggestion::APPLIED || $suggestion->applied_at === null) {
            return [];
        }
        $today = ($today ?? CarbonImmutable::now(self::TZ))->startOfDay();
        $applied = self::day($suggestion->applied_at);
        $channel = (string) $suggestion->channel;
        $reader = $this->reader($channel);
        $baseline = (array) $suggestion->baseline;
        $outcome = (array) $suggestion->outcome;
        $measured = [];
        foreach (self::POINTS as $point => $offset) {
            if (isset($outcome[$point])) {
                continue;
            }
            $from = $applied->addDays(self::WINDOW_DAYS * $offset);
            $to = $from->addDays(self::WINDOW_DAYS - 1);
            if ($today->lte($to)) {
                break;
            }
            $after = null;
            try {
                $scope = $reader !== null ? ($baseline['scope'] ?? $reader->scope($suggestion)) : null;
                $last = is_array($scope) ? $reader->lastDay($scope) : null;
                if (is_array($scope) && ($last === null || $last < $to->toDateString()) && $today->lt($to->addDays(self::WAIT_DAYS))) {
                    break; // the window's data is not collected yet
                }
                if (is_array($scope) && ! self::hasMetrics($channel, $baseline)) {
                    // Applied before the baseline existed (or it failed): the 28 days before apply, from the kept facts.
                    $baseline = $this->baseline($suggestion) + ['recomputed' => true] + $baseline;
                }
                if (is_array($scope) && $last !== null && $last >= $to->toDateString()) {
                    $after = $reader->read($scope, $from->toDateString(), $to->toDateString());
                }
            } catch (Throwable $error) {
                report($error);
            }
            [$verdict, $reason] = self::verdict($channel, $baseline, $after);
            $outcome[$point] = ($after ?? []) + ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'verdict' => $verdict, 'reason' => $reason,
                'measured_at' => now()->toIso8601String()];
            $measured[] = $point;
        }
        if ($measured !== []) {
            $suggestion->forceFill(['baseline' => $baseline, 'outcome' => $outcome, 'measured_at' => now()])->save();
            app(BrandMemoryService::class)->recordOutcome($suggestion);
        }

        return $measured;
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>|null  $after
     * @return array{0: string, 1: string} verdict, one-line reason
     */
    public static function verdict(string $channel, array $baseline, ?array $after): array
    {
        $rule = self::RULES[$channel] ?? null;
        if ($rule === null || $after === null || ! self::hasMetrics($channel, $baseline)) {
            return [self::UNCLEAR, 'veri yok'];
        }
        $volumeLabel = self::METRIC_LABELS[$rule['volume']] ?? $rule['volume'];
        if (max((float) ($baseline[$rule['volume']] ?? 0), (float) ($after[$rule['volume']] ?? 0)) < $rule['min']) {
            return [self::UNCLEAR, 'veri az (en az '.$rule['min'].' '.$volumeLabel.')'];
        }
        $before = $baseline[$rule['primary']] ?? null;
        $now = $after[$rule['primary']] ?? null;
        $label = self::METRIC_LABELS[$rule['primary']] ?? $rule['primary'];
        if (! is_numeric($before) || ! is_numeric($now)) {
            return [self::UNCLEAR, $label.' hesaplanamadı'];
        }
        $before = (float) $before;
        $now = (float) $now;
        $lowerIsBetter = in_array($rule['primary'], self::LOWER_IS_BETTER, true);
        $change = $before == 0.0 ? ($now > 0 ? INF : 0.0) : ($now - $before) / $before;
        $improvement = $lowerIsBetter ? -$change : $change;
        $text = $label.' '.self::format($rule['primary'], $before).' → '.self::format($rule['primary'], $now)
            .(is_finite($change) ? ' ('.($change >= 0 ? '+' : '−').'%'.abs((int) round($change * 100)).')' : '');

        return [$improvement >= self::MIN_CHANGE ? self::WORKED : self::NOT_WORKED, $text];
    }

    /** @param  array<string, mixed>  $baseline */
    public static function hasMetrics(string $channel, array $baseline): bool
    {
        $rule = self::RULES[$channel] ?? null;

        return $rule !== null && isset($baseline['window_days']) && array_key_exists($rule['primary'], $baseline);
    }

    /** @return array{point: string, verdict: string, reason: string}|null the latest measured point of the suggestion */
    public static function latest(Suggestion $suggestion): ?array
    {
        $outcome = (array) $suggestion->outcome;
        foreach (array_reverse(array_keys(self::POINTS)) as $point) {
            if (is_array($outcome[$point] ?? null) && isset($outcome[$point]['verdict'])) {
                return ['point' => $point, 'verdict' => (string) $outcome[$point]['verdict'], 'reason' => (string) ($outcome[$point]['reason'] ?? '')];
            }
        }

        return null;
    }

    /**
     * Bugün "Sonuçlar": the last measured suggestions and the verdict counts of the last $days days.
     *
     * @return array{counts: array<string, int>, items: list<array{brand: string, channel: string, title: string, point: string, verdict: string, label: string, reason: string}>}
     */
    public function summary(int $days = 90, int $limit = 8): array
    {
        $counts = array_fill_keys(array_keys(self::VERDICT_LABELS), 0);
        $items = [];
        $rows = Suggestion::query()->with('brand:id,name')->where('status', Suggestion::APPLIED)->whereNotNull('measured_at')
            ->where('measured_at', '>=', now()->subDays($days))->orderByDesc('measured_at')->orderByDesc('id')
            ->get(['id', 'brand_id', 'channel', 'title', 'outcome', 'measured_at']);
        foreach ($rows as $row) {
            $latest = self::latest($row);
            if ($latest === null) {
                continue;
            }
            $counts[$latest['verdict']] = ($counts[$latest['verdict']] ?? 0) + 1;
            if (count($items) < $limit) {
                $items[] = ['brand' => (string) ($row->brand?->name ?? '—'), 'channel' => $row->channelLabel(), 'title' => (string) $row->title,
                    'point' => $latest['point'] === 'd56' ? '56 gün' : '28 gün', 'verdict' => $latest['verdict'],
                    'label' => self::VERDICT_LABELS[$latest['verdict']] ?? $latest['verdict'], 'reason' => $latest['reason']];
            }
        }

        return ['counts' => $counts, 'items' => $items];
    }

    public static function format(string $metric, float $value): string
    {
        return match (true) {
            in_array($metric, ['cpa', 'cpr', 'cost', 'spend', 'ctr'], true) => number_format($value, 2, ',', '.'),
            $metric === 'position' => number_format($value, 1, ',', '.'),
            default => number_format($value, 0, ',', '.'),
        };
    }

    private static function day(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::TZ)->startOfDay();
    }
}
