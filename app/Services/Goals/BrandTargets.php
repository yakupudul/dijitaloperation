<?php

namespace App\Services\Goals;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandTarget;
use App\Models\User;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Measurement\BrandMeasurementScope;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Marka amaçları (Aşama 5, yakup 2026-10-21: "ayda N talep", no cost-per-lead target): each brand's demand is what the
 * brand conversion dictionary counts as a customer (form, call, message, sale) per source; the goal is proposed from
 * the average of the last three full months plus GROWTH, split over the channels by their share of that demand, and
 * yakup approves it with one click. Refreshed nightly: the months and this month so far always, the proposal only while
 * the goal is not approved.
 */
final class BrandTargets
{
    public const array CHANNELS = ['web' => 'Web sitesi', 'google_ads' => 'Google Ads', 'meta' => 'Meta', 'gbp' => 'İşletme profili'];

    /** Dictionary source => channel. */
    public const array SOURCE_CHANNEL = [
        BrandConversionSource::SOURCE_GA4 => 'web',
        BrandConversionSource::SOURCE_GOOGLE_ADS => 'google_ads',
        BrandConversionSource::SOURCE_META => 'meta',
        BrandConversionSource::SOURCE_GBP => 'gbp',
    ];

    /** Proposed growth over the three-month average. */
    public const float GROWTH = 0.2;

    public const int MONTHS = 3;

    private const string TZ = 'Europe/Istanbul';

    public function __construct(private readonly BrandConversionDictionary $dictionary) {}

    /** Reads the brand's months again and proposes its goal (an approved goal stays as it is). */
    public function refresh(Brand $brand): BrandTarget
    {
        $basis = $this->basis($brand);
        $target = BrandTarget::query()->firstOrNew(['brand_id' => $brand->id]);
        $target->basis = $basis;
        $average = (float) $basis['average'];
        if ($average <= 0) {
            $target->forceFill(['proposed_leads' => null, 'proposed_split' => null, 'proposed_at' => now(),
                'reason' => 'Son '.self::MONTHS.' ayda ölçülen talep yok: dönüşüm sözlüğü boş ya da veri gelmiyor. Ölçüm düzelince amaç önerilir.']);
            if (! $target->isApproved()) {
                $target->status = BrandTarget::UNMEASURED;
            }
        } else {
            $proposed = max((int) ceil($average * (1 + self::GROWTH)), (int) ceil($average) + 1);
            $target->forceFill([
                'proposed_leads' => $proposed,
                'proposed_split' => self::split($proposed, $basis['channels']),
                'proposed_at' => now(),
                'reason' => 'Son '.self::MONTHS.' ayda ayda ortalama '.self::number($average).' talep geldi (form, arama, mesaj). %'.(int) round(self::GROWTH * 100).' büyümeyle ayda '.$proposed.' talep.',
            ]);
            if (! $target->isApproved()) {
                $target->status = BrandTarget::PROPOSED;
            }
        }
        $target->save();

        return $target;
    }

    /** One click: the proposal (or the given number) becomes the brand's goal. */
    public function approve(BrandTarget $target, User $user, ?int $monthlyLeads = null): BrandTarget
    {
        $leads = $monthlyLeads ?? $target->proposed_leads;
        if ($leads === null || $leads < 1) {
            throw ValidationException::withMessages(['target' => 'Amaç en az ayda 1 talep olmalı.']);
        }
        $channels = (array) data_get($target->basis, 'channels', []);
        $target->forceFill([
            'monthly_leads' => $leads,
            'split' => $monthlyLeads === null && $target->proposed_split !== null ? $target->proposed_split : self::split($leads, $channels),
            'status' => BrandTarget::APPROVED,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ])->save();

        return $target;
    }

    /**
     * Proposals waiting for yakup's click, the largest demand first.
     *
     * @return Collection<int, BrandTarget>
     */
    public function waiting(): Collection
    {
        if (! SchemaState::hasTable('brand_targets')) {
            return collect();
        }

        return BrandTarget::query()->with('brand:id,name,logo_url')->where('status', BrandTarget::PROPOSED)->whereNotNull('proposed_leads')
            ->whereHas('brand', fn ($q) => $q->operational())->orderByDesc('proposed_leads')->get();
    }

    /**
     * Where the brand stands this month: the goal, the leads so far (data ends yesterday), what the goal expects by
     * then, the month-end projection and the gap. `state`: ahead | on_track | behind | no_goal.
     *
     * @return array{goal: ?int, mtd: int, expected: ?float, projected: int, gap_pct: ?int, state: string, channels: array<string, array{goal: ?int, mtd: int}>}
     */
    public static function progress(?BrandTarget $target): array
    {
        $basis = (array) ($target?->basis ?? []);
        $mtd = (int) round((float) data_get($basis, 'mtd.total', 0));
        $days = (int) data_get($basis, 'mtd.days', 0);
        $inMonth = (int) data_get($basis, 'mtd.days_in_month', 30);
        $projected = $days > 0 ? (int) round($mtd / $days * $inMonth) : $mtd;
        $goal = $target?->goal();
        $channels = [];
        foreach (array_keys(self::CHANNELS) as $channel) {
            $channels[$channel] = ['goal' => $goal !== null ? (int) ($target->split[$channel] ?? 0) : null, 'mtd' => (int) round((float) data_get($basis, 'mtd.channels.'.$channel, 0))];
        }
        if ($goal === null || $goal < 1) {
            return ['goal' => null, 'mtd' => $mtd, 'expected' => null, 'projected' => $projected, 'gap_pct' => null, 'state' => 'no_goal', 'channels' => $channels];
        }
        $expected = $goal * ($days / max(1, $inMonth));
        $gap = (int) round(($projected - $goal) / $goal * 100);

        return ['goal' => $goal, 'mtd' => $mtd, 'expected' => round($expected, 1), 'projected' => $projected, 'gap_pct' => $gap,
            'state' => match (true) {
                $gap >= 5 => 'ahead',
                $gap >= -10 => 'on_track',
                default => 'behind',
            }, 'channels' => $channels];
    }

    /**
     * The last MONTHS full months and this month so far, per channel, and the brand's leads per organic click.
     *
     * @return array{months: list<array{month: string, total: float, channels: array<string, float>}>, average: float, channels: array<string, float>, mtd: array{total: float, channels: array<string, float>, days: int, days_in_month: int}, web_leads_per_click: ?float, read_at: string}
     */
    public function basis(Brand $brand): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $months = [];
        $clicks = 0;
        for ($i = self::MONTHS; $i >= 1; $i--) {
            $start = $today->startOfMonth()->subMonthsNoOverflow($i);
            [$total, $channels] = $this->read($brand, $start, $start->endOfMonth());
            $months[] = ['month' => $start->format('Y-m'), 'total' => $total, 'channels' => $channels];
            $clicks += $this->organicClicks($brand, $start, $start->endOfMonth());
        }
        $average = round(array_sum(array_column($months, 'total')) / self::MONTHS, 2);
        $channels = [];
        foreach (array_keys(self::CHANNELS) as $channel) {
            $channels[$channel] = round(array_sum(array_map(fn (array $m): float => (float) ($m['channels'][$channel] ?? 0), $months)) / self::MONTHS, 2);
        }
        $yesterday = $today->subDay();
        $mtdDays = $today->day - 1;
        [$mtdTotal, $mtdChannels] = $mtdDays > 0 ? $this->read($brand, $today->startOfMonth(), $yesterday) : [0.0, array_fill_keys(array_keys(self::CHANNELS), 0.0)];
        $webLeads = array_sum(array_map(fn (array $m): float => (float) ($m['channels']['web'] ?? 0), $months));

        return [
            'months' => $months,
            'average' => $average,
            'channels' => $channels,
            'mtd' => ['total' => $mtdTotal, 'channels' => $mtdChannels, 'days' => $mtdDays, 'days_in_month' => $today->daysInMonth],
            'web_leads_per_click' => $clicks > 0 && $webLeads > 0 ? round($webLeads / $clicks, 4) : null,
            'read_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The goal over the channels by their share of the demand; rounding goes to the largest channel so the parts add up.
     *
     * @param  array<string, float>  $channels
     * @return array<string, int>
     */
    public static function split(int $goal, array $channels): array
    {
        $total = array_sum(array_map('floatval', $channels));
        $out = array_fill_keys(array_keys(self::CHANNELS), 0);
        if ($total <= 0) {
            $out['web'] = $goal;

            return $out;
        }
        foreach ($out as $channel => $_) {
            $out[$channel] = (int) floor($goal * ((float) ($channels[$channel] ?? 0)) / $total);
        }
        $largest = array_search(max(array_map('floatval', $channels)), array_map('floatval', $channels), true);
        $out[is_string($largest) && isset($out[$largest]) ? $largest : 'web'] += $goal - array_sum($out);

        return $out;
    }

    public static function number(float $value): string
    {
        return $value >= 10 || abs($value - round($value)) < 0.05 ? (string) (int) round($value) : str_replace('.', ',', (string) round($value, 1));
    }

    /** @return array{0: float, 1: array<string, float>} */
    private function read(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $channels = array_fill_keys(array_keys(self::CHANNELS), 0.0);
        try {
            $totals = DB::transaction(fn (): array => $this->dictionary->totals($brand, $from, $to));
        } catch (Throwable $error) {
            report($error);

            return [0.0, $channels];
        }
        foreach ($totals['by_source'] as $source => $value) {
            $channel = self::SOURCE_CHANNEL[$source] ?? null;
            if ($channel !== null) {
                $channels[$channel] = round($channels[$channel] + (float) $value, 2);
            }
        }

        return [round((float) $totals['total'], 2), $channels];
    }

    private function organicClicks(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if (! SchemaState::hasTable('gsc_property_daily')) {
            return 0;
        }
        try {
            return (int) DB::transaction(fn () => BrandMeasurementScope::for($brand)->rows('gsc_property_daily', $from, $to)->where('search_type', 'web')->sum('clicks'));
        } catch (Throwable $error) {
            report($error);

            return 0;
        }
    }
}
