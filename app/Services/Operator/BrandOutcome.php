<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Measurement\BrandMeasurementScope;
use App\Support\SchemaState;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Marka sayfası › Sonuç (arayüz kararı 2026-11-17): the one question the brand page answers first. How many customer
 * contacts did this brand get this month (call, WhatsApp, form), what did one contact cost, and how does that compare
 * with the same days of last month. Contacts come from the brand's conversion dictionary (only rows that count as a
 * customer); spend is Google Ads + Meta of the same days. A kind without any counted source is "not measured", never 0.
 */
final class BrandOutcome
{
    public const string TZ = 'Europe/Istanbul';

    public const int CACHE_MINUTES = 30;

    /** Contact kind => [label, conversion types]. */
    public const array KINDS = [
        'call' => ['Arama', [ConversionGoalTypes::PHONE_CALL]],
        'whatsapp' => ['WhatsApp', [ConversionGoalTypes::WHATSAPP_CONVERSATION]],
        'form' => ['Form', [ConversionGoalTypes::FORM_SUBMISSION, ConversionGoalTypes::APPOINTMENT_REQUEST, ConversionGoalTypes::BOOKING, ConversionGoalTypes::QUALIFIED_LEAD]],
    ];

    public function __construct(private readonly BrandConversionDictionary $dictionary) {}

    /**
     * @return array{from: string, to: string, prev_from: string, prev_to: string, days: int, month_so_far: bool, has_sources: bool,
     *     kinds: list<array{key: string, label: string, now: int, prev: int, measured: bool}>,
     *     total: array{now: int, prev: int, change_pct: ?int},
     *     spend: ?array{now: float, prev: float, currency: string}, cost_per: ?float, cost_per_prev: ?float}
     */
    public function for(Brand $brand): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();

        return Cache::remember('brand-outcome.'.$brand->id.'.'.$today->toDateString(), now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->read($brand, $today));
    }

    public static function forget(int $brandId): void
    {
        Cache::forget('brand-outcome.'.$brandId.'.'.CarbonImmutable::now(self::TZ)->toDateString());
    }

    /** @return array<string, mixed> */
    private function read(Brand $brand, CarbonImmutable $today): array
    {
        // Data ends yesterday. On the 1st there is no day of this month yet: last month against the one before.
        $soFar = $today->day > 1;
        $from = $soFar ? $today->startOfMonth() : $today->subMonthNoOverflow()->startOfMonth();
        $to = $soFar ? $today->subDay() : $from->endOfMonth()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $prevFrom = $from->subMonthNoOverflow()->startOfMonth();
        $prevTo = $prevFrom->addDays($days - 1);
        if ($prevTo->greaterThan($prevFrom->endOfMonth())) {
            $prevTo = $prevFrom->endOfMonth()->startOfDay();
        }

        $now = $this->totals($brand, $from, $to);
        $prev = $this->totals($brand, $prevFrom, $prevTo);
        $counted = $this->countedTypes($brand);

        $kinds = [];
        $known = [];
        foreach (self::KINDS as $key => [$label, $types]) {
            $known = [...$known, ...$types];
            $kinds[] = [
                'key' => $key,
                'label' => $label,
                'now' => (int) round(array_sum(array_intersect_key($now['by_type'], array_flip($types)))),
                'prev' => (int) round(array_sum(array_intersect_key($prev['by_type'], array_flip($types)))),
                'measured' => array_intersect($types, $counted) !== [],
            ];
        }
        $otherNow = (int) round(array_sum(array_diff_key($now['by_type'], array_flip($known))));
        $otherPrev = (int) round(array_sum(array_diff_key($prev['by_type'], array_flip($known))));
        if ($otherNow > 0 || $otherPrev > 0) {
            $kinds[] = ['key' => 'other', 'label' => 'Diğer', 'now' => $otherNow, 'prev' => $otherPrev, 'measured' => true];
        }

        $totalNow = (int) array_sum(array_column($kinds, 'now'));
        $totalPrev = (int) array_sum(array_column($kinds, 'prev'));
        $spend = $this->spend($brand, $from, $to, $prevFrom, $prevTo);

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'prev_from' => $prevFrom->toDateString(), 'prev_to' => $prevTo->toDateString(),
            'days' => $days, 'month_so_far' => $soFar, 'has_sources' => $counted !== [],
            'kinds' => $kinds,
            'total' => ['now' => $totalNow, 'prev' => $totalPrev, 'change_pct' => $totalPrev > 0 ? (int) round(($totalNow - $totalPrev) / $totalPrev * 100) : null],
            'spend' => $spend,
            'cost_per' => $spend !== null && $spend['now'] > 0 && $totalNow > 0 ? round($spend['now'] / $totalNow, 2) : null,
            'cost_per_prev' => $spend !== null && $spend['prev'] > 0 && $totalPrev > 0 ? round($spend['prev'] / $totalPrev, 2) : null,
        ];
    }

    /** @return array{total: float, by_type: array<string, float>} */
    private function totals(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            return DB::transaction(fn (): array => $this->dictionary->totals($brand, $from, $to));
        } catch (Throwable $error) {
            report($error);

            return ['total' => 0.0, 'by_type' => []];
        }
    }

    /** @return list<string> conversion types the brand counts as a customer in at least one source */
    private function countedTypes(Brand $brand): array
    {
        try {
            return BrandConversionSource::query()->where('brand_id', $brand->id)->where('counts', true)->get()
                ->filter(fn (BrandConversionSource $row): bool => $this->dictionary->countsAsCustomer($row))
                ->pluck('conversion_type')->map(fn ($type): string => (string) $type)->unique()->values()->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /**
     * Google Ads + Meta spend of the two windows. Null when the brand has no ad rows at all, or the accounts use
     * different currencies (the sum would be meaningless).
     *
     * @return array{now: float, prev: float, currency: string}|null
     */
    private function spend(Brand $brand, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): ?array
    {
        try {
            $scope = BrandMeasurementScope::for($brand);
            if ($scope->isEmpty()) {
                return null;
            }
            $split = $from->toDateString();
            $byCurrency = [];
            foreach (['google_ads_account_daily' => 'cost_amount', 'meta_account_daily' => 'spend'] as $table => $column) {
                if (! SchemaState::hasTable($table)) {
                    continue;
                }
                $rows = $scope->rows($table, $prevFrom, $to)
                    ->where(fn ($q) => $q->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])
                        ->orWhereBetween('reporting_date', [$prevFrom->toDateString(), $prevTo->toDateString()]))
                    ->selectRaw('currency, sum(case when reporting_date >= ? then '.$column.' else 0 end) as now_sum, sum(case when reporting_date < ? then '.$column.' else 0 end) as prev_sum', [$split, $split])
                    ->groupBy('currency')->get();
                foreach ($rows as $row) {
                    $currency = strtoupper(trim((string) ($row->currency ?? ''))) ?: 'TRY';
                    $byCurrency[$currency] ??= ['now' => 0.0, 'prev' => 0.0];
                    $byCurrency[$currency]['now'] += (float) ($row->now_sum ?? 0);
                    $byCurrency[$currency]['prev'] += (float) ($row->prev_sum ?? 0);
                }
            }
            $byCurrency = array_filter($byCurrency, fn (array $s): bool => $s['now'] > 0 || $s['prev'] > 0);
            if (count($byCurrency) !== 1) {
                return null;
            }
            $currency = (string) array_key_first($byCurrency);

            return ['now' => round($byCurrency[$currency]['now'], 2), 'prev' => round($byCurrency[$currency]['prev'], 2), 'currency' => $currency];
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }
}
