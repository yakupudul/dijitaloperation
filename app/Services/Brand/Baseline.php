<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandBaseline;
use App\Models\BrandConversionSource;
use App\Models\User;
use App\Services\Ads\BrandServiceScorecard;
use App\Services\Goals\BrandTargets;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Measurement\BrandMeasurementScope;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Başlangıç sayıları (Marka Düzeni 2.3 / 3.10): what a brand had in 30 full days when its setup began, kept as the
 * base every later month is read against. Per channel, never added up across channels: demand (what the brand's
 * conversion dictionary counts as a customer) from the site, Google Ads, Meta and the Business Profile, organic
 * clicks, and each main service's clicks and pages. Full days only: the window ends LAG days back, where every
 * source has delivered.
 */
final class Baseline
{
    /** Days back where every source has delivered its numbers (Search Console is the slowest). */
    public const int LAG = 3;

    public const int DAYS = 30;

    public function __construct(
        private readonly BrandConversionDictionary $dictionary,
        private readonly BrandServiceScorecard $scorecard,
    ) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the last 30 full days */
    public static function window(): array
    {
        $to = CarbonImmutable::now('Europe/Istanbul')->startOfDay()->subDays(self::LAG);

        return [$to->subDays(self::DAYS - 1), $to];
    }

    public function of(Brand $brand): ?BrandBaseline
    {
        return SchemaState::hasTable('brand_baselines') ? BrandBaseline::query()->where('brand_id', $brand->id)->first() : null;
    }

    /** Takes the start numbers once; `$again` replaces them (a setup restarted after access was fixed). */
    public function take(Brand $brand, ?User $by = null, bool $again = false): BrandBaseline
    {
        $existing = $this->of($brand);
        if ($existing !== null && ! $again) {
            return $existing;
        }
        [$from, $to] = self::window();

        return BrandBaseline::query()->updateOrCreate(['brand_id' => $brand->id], [
            'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'numbers' => $this->measure($brand, $from, $to), 'taken_by' => $by?->id,
        ]);
    }

    /**
     * @return array{demand: array<string, float>, demand_total: float, organic_clicks: int, services: list<array{name: string, clicks: int, pages: int}>}
     */
    public function measure(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $demand = array_fill_keys(array_keys(BrandTargets::CHANNELS), 0.0);
        $total = 0.0;
        try {
            $totals = DB::transaction(fn (): array => $this->dictionary->totals($brand, $from, $to));
            $total = (float) $totals['total'];
            foreach ($totals['by_source'] as $source => $value) {
                $channel = BrandTargets::SOURCE_CHANNEL[$source] ?? null;
                if ($channel !== null) {
                    $demand[$channel] = round($demand[$channel] + (float) $value, 1);
                }
            }
        } catch (Throwable $error) {
            report($error);
        }
        $clicks = 0;
        try {
            $clicks = SchemaState::hasTable('gsc_property_daily')
                ? (int) DB::transaction(fn () => BrandMeasurementScope::for($brand)->rows('gsc_property_daily', $from, $to)->where('search_type', 'web')->sum('clicks')) : 0;
        } catch (Throwable $error) {
            report($error);
        }
        $services = [];
        try {
            foreach ($this->scorecard->scorecard($brand)['rows'] as $row) {
                if ($row['main'] ?? false) {
                    $services[] = ['name' => (string) $row['name'], 'clicks' => (int) ($row['web']['clicks'] ?? 0), 'pages' => (int) ($row['web']['pages'] ?? 0)];
                }
            }
        } catch (Throwable $error) {
            report($error);
        }

        return ['demand' => $demand, 'demand_total' => round($total, 1), 'organic_clicks' => $clicks, 'services' => $services];
    }

    /**
     * The start beside the last 30 full days, line by line.
     *
     * @return array{baseline: BrandBaseline, from: CarbonImmutable, to: CarbonImmutable, lines: list<array{label: string, start: float, now: float, change: ?float}>, services: list<array{name: string, start: int, now: int, pages: int}>, measured: bool}|null
     */
    public function card(Brand $brand): ?array
    {
        $baseline = $this->of($brand);
        if ($baseline === null) {
            return null;
        }
        [$from, $to] = self::window();
        $start = (array) $baseline->numbers;
        $now = $to->isSameDay($baseline->period_to) ? $start : $this->measure($brand, $from, $to);
        $line = fn (string $label, float $a, float $b): array => ['label' => $label, 'start' => $a, 'now' => $b, 'change' => $a > 0 ? round(($b - $a) / $a * 100, 0) : null];
        $lines = [];
        foreach (BrandTargets::CHANNELS as $channel => $label) {
            $a = (float) ($start['demand'][$channel] ?? 0);
            $b = (float) ($now['demand'][$channel] ?? 0);
            if ($a > 0 || $b > 0) {
                $lines[] = $line($label.' talebi', $a, $b);
            }
        }
        $lines[] = $line('Organik tık', (float) ($start['organic_clicks'] ?? 0), (float) ($now['organic_clicks'] ?? 0));
        $nowServices = collect($now['services'] ?? [])->keyBy('name');
        $services = collect($start['services'] ?? [])->map(fn (array $s): array => ['name' => (string) $s['name'], 'start' => (int) $s['clicks'],
            'now' => (int) ($nowServices[$s['name']]['clicks'] ?? 0), 'pages' => (int) ($nowServices[$s['name']]['pages'] ?? $s['pages'])])->values()->all();
        $measured = BrandConversionSource::query()->where('brand_id', $brand->id)->where('counts', true)->exists();

        return ['baseline' => $baseline, 'from' => $from, 'to' => $to, 'lines' => $lines, 'services' => $services, 'measured' => $measured];
    }
}
