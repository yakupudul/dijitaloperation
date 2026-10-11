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
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sonuç takibi (Faz 9; 30 / 90 gün since 2026-10-11) — the ONE outcome mechanism of the suggestions table. Every change
 * applied after approval (the repair desk's SEO fields, noindex / canonical, internal links, Business Profile fields,
 * Google Ads negatives / budget / settings, published content; the channel screens' "Uygulandı") is measured here.
 *
 * Baseline (on apply): the channel's numbers of the 30 days before apply (ending on the last collected day, at most
 * the day before apply), stored as `{<metric>: value, …, window_days: 30, from, to, captured_at, scope: {…}}`.
 *
 * Measurement (`moxdop:outcomes:measure`, nightly): point `dN` compares the N days after apply (apply day + 1 … + N)
 * with the N days before (apply day − N … − 1) — equal windows, both read from the stored facts. Each point is stored
 * once in `outcome[dN]` with the after numbers, `before`, the verdict, a reason and a one-line `line` ("+38 tıklama
 * (30 gün)"). A point waits at most WAIT_DAYS for late data. Points d28 / d56 measured before 2026-10-11 stay readable.
 *
 * Verdict rule (only here): the channel's primary metric (RULES) after vs before.
 *  - ölçülemedi (unclear): no reader / scope, the source was not collected for the whole before window, the after
 *    window was not collected in time, or the volume metric is below its minimum in both windows — with the reason;
 *  - işe yaradı (worked): the primary metric improved (in its better direction) by at least MIN_CHANGE (10 %);
 *  - kötüleşti (not_worked): it got worse by at least MIN_CHANGE;
 *  - değişmedi (neutral): otherwise.
 *
 * Learning: workTypes() turns the measured verdicts into a small bounded ordering weight per work type (see weight()),
 * which the repair desk uses to order its packages and brands.
 */
final class OutcomeTracker
{
    public const string TZ = 'Europe/Istanbul';

    /** Days of the baseline stored on apply. */
    public const int WINDOW_DAYS = 30;

    /** Point => days after apply; the point compares those days with the same number of days before apply. */
    public const array POINTS = ['d30' => 30, 'd90' => 90];

    /** Points measured before 2026-10-11 (28-day windows): read for display and learning, never measured again. */
    public const array LEGACY_POINTS = ['d28' => 28, 'd56' => 56];

    /** Days to wait after a window's end for its data to be collected; then it is measured as it is (ölçülemedi). */
    public const int WAIT_DAYS = 14;

    public const float MIN_CHANGE = 0.10;

    public const string WORKED = 'worked';

    public const string NEUTRAL = 'neutral';

    public const string NOT_WORKED = 'not_worked';

    public const string UNCLEAR = 'unclear';

    public const array VERDICT_LABELS = [self::WORKED => 'işe yaradı', self::NEUTRAL => 'değişmedi', self::NOT_WORKED => 'kötüleşti', self::UNCLEAR => 'ölçülemedi'];

    /** channel => metric reader (one small class per channel). */
    public const array READERS = [
        'search' => SearchOutcomeReader::class,
        'maps' => MapsOutcomeReader::class,
        'google_ads' => GoogleAdsOutcomeReader::class,
        'meta' => MetaOutcomeReader::class,
    ];

    /** channel => primary metric (decides), volume metric and its minimum (below it in both periods = ölçülemedi). */
    public const array RULES = [
        'search' => ['primary' => 'clicks', 'volume' => 'clicks', 'min' => 20],
        'maps' => ['primary' => 'actions', 'volume' => 'actions', 'min' => 20],
        'google_ads' => ['primary' => 'cpa', 'volume' => 'conversions', 'min' => 5],
        'meta' => ['primary' => 'cpr', 'volume' => 'results', 'min' => 10],
    ];

    /** Metrics where lower is better; every other metric is better when higher. */
    public const array LOWER_IS_BETTER = ['position', 'cpa', 'cpr'];

    /** Ratio metrics: the one-line result shows their change in percent, count metrics show the difference. */
    public const array RATIO_METRICS = ['position', 'cpa', 'cpr', 'ctr'];

    public const array METRIC_LABELS = [
        'clicks' => 'tıklama', 'impressions' => 'gösterim', 'position' => 'sıra', 'actions' => 'etkileşim', 'views' => 'görüntülenme',
        'calls' => 'arama', 'directions' => 'yol tarifi', 'website_clicks' => 'site tıklaması', 'cost' => 'maliyet', 'conversions' => 'dönüşüm',
        'cpa' => 'dönüşüm başı maliyet', 'spend' => 'harcama', 'results' => 'sonuç', 'cpr' => 'sonuç başı maliyet', 'ctr' => 'TO %',
        'sessions' => 'oturum',
    ];

    /** Look-back of the learned work-type record, and its cache (cleared by the nightly measurement). */
    public const int LEARN_DAYS = 365;

    public const string LEARN_CACHE = 'outcomes:work-types';

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
     * The WINDOW_DAYS (30) before apply (applied_at, else now), ending on the last collected day: what the operator
     * saw at apply. The measurement reads its own equal before-window from the stored facts.
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
     * Measures the due points (d30, d90) of an applied suggestion; each point is measured once.
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
        foreach (self::POINTS as $point => $days) {
            if (isset($outcome[$point])) {
                continue;
            }
            $beforeFrom = $applied->subDays($days);
            $beforeTo = $applied->subDay();
            $from = $applied->addDay();
            $to = $applied->addDays($days);
            if ($today->lte($to)) {
                break;
            }
            $before = null;
            $after = null;
            $missing = $reader === null ? 'bu iş türü ölçülmüyor' : null;
            try {
                $scope = $reader !== null ? ($baseline['scope'] ?? $reader->scope($suggestion)) : null;
                $last = is_array($scope) ? $reader->lastDay($scope) : null;
                if (is_array($scope) && ($last === null || $last < $to->toDateString()) && $today->lt($to->addDays(self::WAIT_DAYS))) {
                    break; // the window's data is not collected yet
                }
                $first = is_array($scope) ? $reader->firstDay($scope) : null;
                if (is_array($scope) && $first !== null && $first > $beforeFrom->toDateString()) {
                    $missing = 'öncesi için '.$days.' günlük veri yok';
                } elseif (is_array($scope) && $last !== null && $last >= $to->toDateString()) {
                    $before = $reader->read($scope, $beforeFrom->toDateString(), $beforeTo->toDateString());
                    $after = $reader->read($scope, $from->toDateString(), $to->toDateString());
                }
            } catch (Throwable $error) {
                report($error);
            }
            [$verdict, $reason] = $missing !== null ? [self::UNCLEAR, $missing] : self::verdict($channel, $before, $after);
            $outcome[$point] = ($after ?? []) + ['before' => $before, 'before_from' => $beforeFrom->toDateString(), 'before_to' => $beforeTo->toDateString(),
                'from' => $from->toDateString(), 'to' => $to->toDateString(), 'verdict' => $verdict, 'reason' => $reason,
                'line' => self::line($channel, $point, $verdict, $reason, $before, $after), 'measured_at' => now()->toIso8601String()];
            $measured[] = $point;
        }
        if ($measured !== []) {
            $suggestion->forceFill(['outcome' => $outcome, 'measured_at' => now()])->save();
            app(BrandMemoryService::class)->recordOutcome($suggestion);
        }

        return $measured;
    }

    /**
     * Before vs after on the channel's primary metric.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array{0: string, 1: string} verdict, one-line reason
     */
    public static function verdict(string $channel, ?array $before, ?array $after): array
    {
        $rule = self::RULES[$channel] ?? null;
        if ($rule === null || $after === null || ! self::hasMetrics($channel, (array) $before)) {
            return [self::UNCLEAR, 'veri yok'];
        }
        $volumeLabel = self::METRIC_LABELS[$rule['volume']] ?? $rule['volume'];
        if (max((float) ($before[$rule['volume']] ?? 0), (float) ($after[$rule['volume']] ?? 0)) < $rule['min']) {
            return [self::UNCLEAR, 'veri az (en az '.$rule['min'].' '.$volumeLabel.')'];
        }
        $old = $before[$rule['primary']] ?? null;
        $now = $after[$rule['primary']] ?? null;
        $label = self::METRIC_LABELS[$rule['primary']] ?? $rule['primary'];
        if (! is_numeric($old) || ! is_numeric($now)) {
            return [self::UNCLEAR, $label.' hesaplanamadı'];
        }
        $change = self::change((float) $old, (float) $now);
        $improvement = in_array($rule['primary'], self::LOWER_IS_BETTER, true) ? -$change : $change;
        $text = $label.' '.self::format($rule['primary'], (float) $old).' → '.self::format($rule['primary'], (float) $now)
            .(is_finite($change) ? ' ('.($change >= 0 ? '+' : '−').'%'.abs((int) round($change * 100)).')' : '');

        return [match (true) {
            $improvement >= self::MIN_CHANGE => self::WORKED,
            $improvement <= -self::MIN_CHANGE => self::NOT_WORKED,
            default => self::NEUTRAL,
        }, $text];
    }

    /**
     * The one short line of a measured point: "+38 tıklama (30 gün)", "dönüşüm başı maliyet −%12 (90 gün)",
     * "ölçülemedi: veri az (en az 20 tıklama) (30 gün)".
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function line(string $channel, string $point, string $verdict, string $reason, ?array $before, ?array $after): string
    {
        $days = ' ('.self::pointLabel($point).')';
        $metric = self::RULES[$channel]['primary'] ?? null;
        if ($verdict === self::UNCLEAR || $metric === null || ! is_numeric($before[$metric] ?? null) || ! is_numeric($after[$metric] ?? null)) {
            return 'ölçülemedi: '.$reason.$days;
        }
        $old = (float) $before[$metric];
        $now = (float) $after[$metric];
        $label = self::METRIC_LABELS[$metric] ?? $metric;
        if (in_array($metric, self::RATIO_METRICS, true)) {
            $change = self::change($old, $now);

            return $label.' '.(is_finite($change) ? ($change >= 0 ? '+' : '−').'%'.abs((int) round($change * 100)) : self::format($metric, $old).' → '.self::format($metric, $now)).$days;
        }
        $delta = $now - $old;

        return ($delta >= 0 ? '+' : '−').self::format($metric, abs($delta)).' '.$label.$days;
    }

    public static function pointLabel(string $point): string
    {
        return (self::POINTS[$point] ?? self::LEGACY_POINTS[$point] ?? (int) ltrim($point, 'd')).' gün';
    }

    /** @param  array<string, mixed>  $baseline */
    public static function hasMetrics(string $channel, array $baseline): bool
    {
        $rule = self::RULES[$channel] ?? null;

        return $rule !== null && array_key_exists($rule['primary'], $baseline);
    }

    /** @return array{point: string, verdict: string, reason: string, line: string}|null the latest measured point of the suggestion */
    public static function latest(Suggestion $suggestion): ?array
    {
        $outcome = (array) $suggestion->outcome;
        foreach (['d90', 'd56', 'd30', 'd28'] as $point) {
            if (is_array($outcome[$point] ?? null) && isset($outcome[$point]['verdict'])) {
                $reason = (string) ($outcome[$point]['reason'] ?? '');

                return ['point' => $point, 'verdict' => (string) $outcome[$point]['verdict'], 'reason' => $reason,
                    'line' => (string) ($outcome[$point]['line'] ?? ($reason !== '' ? $reason.' ('.self::pointLabel($point).')' : self::pointLabel($point)))];
            }
        }

        return null;
    }

    /**
     * Bugün "Sonuçlar": the last measured suggestions and the verdict counts of the last $days days.
     *
     * @return array{counts: array<string, int>, items: list<array{brand: string, channel: string, title: string, point: string, verdict: string, label: string, reason: string, line: string}>}
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
                    'point' => self::pointLabel($latest['point']), 'verdict' => $latest['verdict'],
                    'label' => self::VERDICT_LABELS[$latest['verdict']] ?? $latest['verdict'], 'reason' => $latest['reason'], 'line' => $latest['line']];
            }
        }

        return ['counts' => $counts, 'items' => $items];
    }

    /**
     * Work type of a suggestion for learning: its action type, plus the changed field where one action type covers
     * different changes (Google Ads budget vs network, Business Profile hours vs phone).
     *
     * @param  array<string, mixed>  $action
     */
    public static function workType(string $actionType, array $action): string
    {
        $field = $action['field'] ?? null;

        return is_string($field) && $field !== '' && in_array($actionType, ['ads_change', 'gbp_profile_fields'], true) ? $actionType.'.'.$field : $actionType;
    }

    /**
     * What each work type's applied changes did (latest measured point per suggestion, last LEARN_DAYS days, every
     * brand), with its ordering weight. Cached until the next nightly measurement.
     *
     * @return array<string, array{won: int, neutral: int, lost: int, unclear: int, measured: int, weight: float}>
     */
    public function workTypes(): array
    {
        return Cache::remember(self::LEARN_CACHE, now()->addHours(6), function (): array {
            $types = [];
            Suggestion::query()->where('status', Suggestion::APPLIED)->whereNotNull('measured_at')->where('measured_at', '>=', now()->subDays(self::LEARN_DAYS))
                ->select(['id', 'action_type', 'action', 'outcome'])->chunkById(500, function ($rows) use (&$types): void {
                    foreach ($rows as $row) {
                        $latest = self::latest($row);
                        if ($latest === null) {
                            continue;
                        }
                        $type = self::workType((string) $row->action_type, (array) $row->action);
                        $types[$type] ??= ['won' => 0, 'neutral' => 0, 'lost' => 0, 'unclear' => 0];
                        $types[$type][match ($latest['verdict']) {
                            self::WORKED => 'won', self::NEUTRAL => 'neutral', self::NOT_WORKED => 'lost', default => 'unclear',
                        }]++;
                    }
                });

            return array_map(function (array $t): array {
                $t['measured'] = $t['won'] + $t['neutral'] + $t['lost'];

                return $t + ['weight' => self::weight($t['won'], $t['lost'], $t['measured'])];
            }, $types);
        });
    }

    public static function forgetWorkTypes(): void
    {
        Cache::forget(self::LEARN_CACHE);
    }

    /**
     * Learned ordering weight of a work type (the repair desk multiplies a package's size and a brand's gain by it).
     *
     * weight = 1 + range × (won − lost) / measured, with range = `moxdop-advisor.brain.weight_range` (0.2, at most 0.5),
     * so it stays within 0.8 … 1.2: a type whose changes keep winning moves ahead of a package of about the same size,
     * one whose changes keep making things worse falls behind, "değişmedi" pulls toward 1. It is 1.0 until the type
     * has `brain.min_measured` (5) measured results, and "ölçülemedi" never counts (no data is not a failure). Small
     * and bounded on purpose: the risk lanes and the operator's priorities still decide; the weight only reorders
     * similar work and never hides a row.
     */
    public static function weight(int $won, int $lost, int $measured): float
    {
        $min = max(1, (int) config('moxdop-advisor.brain.min_measured', 5));
        $range = max(0.0, min(0.5, (float) config('moxdop-advisor.brain.weight_range', 0.2)));
        if ($measured < $min) {
            return 1.0;
        }

        return round(1 + $range * ($won - $lost) / $measured, 3);
    }

    public static function format(string $metric, float $value): string
    {
        return match (true) {
            in_array($metric, ['cpa', 'cpr', 'cost', 'spend', 'ctr'], true) => number_format($value, 2, ',', '.'),
            $metric === 'position' => number_format($value, 1, ',', '.'),
            default => number_format($value, 0, ',', '.'),
        };
    }

    private static function change(float $before, float $now): float
    {
        return $before == 0.0 ? ($now > 0 ? INF : 0.0) : ($now - $before) / $before;
    }

    private static function day(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::TZ)->startOfDay();
    }
}
