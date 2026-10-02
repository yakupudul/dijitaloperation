<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\UsesSiteRange;
use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\DigitalAsset;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\Analysis\SitePagesReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Web sitesi › Analiz: Search Console × GA4 × pages for the screen's date range against the comparison period. Six
 * scorecards (Tıklama, Gösterim, TO, Ortalama sıra · Oturum, Dönüşüm) with change and a small line; a click picks the
 * metric of the two charts on one time axis (Search Console above, GA4 below). Then the funnel (Gösterim → Tıklama →
 * Organik oturum → Dönüşüm), four findings computed from the stored rows and the Sayfa karnesi (per page Search Console
 * + GA4 and a rule-based diagnosis, CSV). Read only; nothing is estimated — a missing source shows "—".
 */
final class AnalyticsTab extends Component
{
    use UsesSiteRange;
    use WebsiteTab {
        mount as mountTab;
    }

    public const array GSC_METRICS = ['clicks' => 'Tıklama', 'impressions' => 'Gösterim', 'ctr' => 'TO', 'position' => 'Ortalama sıra'];

    public const array GA4_METRICS = ['sessions' => 'Oturum', 'key_events' => 'Dönüşüm'];

    /** Sayfa karnesi rows. */
    private const int CARD_ROWS = 15;

    /** A service page with at least this many impressions and no click is a finding. */
    private const int VISIBLE_IMPRESSIONS = 100;

    #[Url(as: 'gsc')]
    public string $gscMetric = 'clicks';

    #[Url(as: 'ga4')]
    public string $ga4Metric = 'sessions';

    /** @param  array{days?: int, start?: ?string, end?: ?string, compare?: string}  $range */
    public function mount(int $assetId, array $range = []): void
    {
        $this->mountTab($assetId);
        $this->range = $range;
    }

    public function pick(string $metric): void
    {
        if (array_key_exists($metric, self::GSC_METRICS)) {
            $this->gscMetric = $metric;
        } elseif (array_key_exists($metric, self::GA4_METRICS)) {
            $this->ga4Metric = $metric;
        }
    }

    /** Sayfa karnesi as CSV (every page with data, not only the first rows). */
    public function csv(SitePagesReader $pages): StreamedResponse
    {
        $site = $this->site();
        $days = $this->siteRange()->days;
        $rows = $this->scorecard($pages->rows($site, $days), PHP_INT_MAX);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Sayfa', 'Başlık', 'Tür', 'Tıklama', 'Gösterim', 'TO %', 'Sıra', 'Oturum', 'Dönüşüm', 'Değişim %', 'Teşhis']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['path'], $row['title'], SitePagesReader::TYPES[$row['type']] ?? $row['type'], $row['clicks'], $row['impressions'],
                    $row['ctr'], $row['position'], $row['sessions'], $row['key_events'], $row['delta'], $row['diagnosis']]);
            }
            fclose($out);
        }, 'sayfa-karnesi-'.$site->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(SiteAnalysisReader $analysis, SitePagesReader $pages): View
    {
        $site = $this->site();
        $days = $this->siteRange()->days;
        $trend = $pages->trend($site, $days);
        $totals = $analysis->totals($site, $days);
        $rows = $pages->rows($site, $days);
        $gscMetric = array_key_exists($this->gscMetric, self::GSC_METRICS) ? $this->gscMetric : 'clicks';
        $ga4Metric = array_key_exists($this->ga4Metric, self::GA4_METRICS) ? $this->ga4Metric : 'sessions';

        return view('livewire.operator.website.v2.analytics-tab', [
            'site' => $site,
            'trend' => $trend,
            'cards' => $this->cards($trend, $totals),
            'gscMetric' => $gscMetric,
            'ga4Metric' => $ga4Metric,
            'gscChart' => self::series($trend, $gscMetric),
            'ga4Chart' => self::series($trend, $ga4Metric),
            'funnel' => $this->funnel($trend, $analysis->organic($site, $days)),
            'insights' => $this->insights($site, $days, $rows, $analysis, $trend),
            'scorecard' => $this->scorecard($rows, self::CARD_ROWS),
            'pageCount' => count(array_filter($rows, fn (array $r): bool => $r['impressions'] > 0 || $r['sessions'] > 0)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $trend
     * @param  array{current: array<string, mixed>, previous: array<string, mixed>}  $totals
     * @return array<string, array{label: string, source: string, value: int|float|null, previous: int|float|null, delta: ?float, better: ?bool, points: list<int|float|null>, format: string}>
     */
    private function cards(array $trend, array $totals): array
    {
        $c = $trend['current'];
        $p = $trend['previous'];
        $ctr = fn (array $m): ?float => $m['impressions'] > 0 ? round($m['clicks'] / $m['impressions'] * 100, 2) : null;
        $values = [
            'clicks' => [$c['clicks'], $p['clicks'], 'gsc', 'int'],
            'impressions' => [$c['impressions'], $p['impressions'], 'gsc', 'int'],
            'ctr' => [$ctr($c), $ctr($p), 'gsc', 'pct'],
            'position' => [$totals['current']['position'], $totals['previous']['position'], 'gsc', 'dec'],
            'sessions' => [$c['sessions'], $p['sessions'], 'ga4', 'int'],
            'key_events' => [$c['key_events'], $p['key_events'], 'ga4', 'dec'],
        ];
        $out = [];
        foreach ($values as $metric => [$value, $previous, $source, $format]) {
            $has = $source === 'gsc' ? $trend['has_gsc'] : $trend['has_ga4'];
            $delta = $has && $value !== null && $previous !== null && (float) $previous != 0.0 ? round(($value - $previous) / $previous * 100, 1) : null;
            $out[$metric] = [
                'label' => self::GSC_METRICS[$metric] ?? self::GA4_METRICS[$metric], 'source' => $source,
                'value' => $has ? $value : null, 'previous' => $has ? $previous : null, 'delta' => $delta,
                // Lower position is better.
                'better' => $delta === null || $delta == 0.0 ? null : ($metric === 'position' ? $delta < 0 : $delta > 0),
                'points' => array_column(self::series($trend, $metric)['current'], 'value'),
                'format' => $format,
            ];
        }

        return $out;
    }

    /**
     * Daily points of one metric for the period and the comparison period (aligned by day index).
     *
     * @param  array<string, mixed>  $trend
     * @return array{metric: string, current: list<array{date: string, value: int|float|null}>, previous: list<array{date: string, value: int|float|null}>, max: float, min: float}
     */
    public static function series(array $trend, string $metric): array
    {
        $value = fn (array $point): int|float|null => match ($metric) {
            'ctr' => $point['impressions'] > 0 ? round($point['clicks'] / $point['impressions'] * 100, 2) : null,
            'position' => $point['position'] ?? null,
            default => $point[$metric] ?? 0,
        };
        $current = array_map(fn (array $p): array => ['date' => $p['date'], 'value' => $value($p)], $trend['series']);
        $previous = array_map(fn (array $p): array => ['date' => $p['date'], 'value' => $value($p)], $trend['previous_series'] ?? []);
        $all = array_filter([...array_column($current, 'value'), ...array_column($previous, 'value')], fn ($v): bool => $v !== null);

        return ['metric' => $metric, 'current' => $current, 'previous' => $previous, 'max' => $all === [] ? 0.0 : (float) max($all), 'min' => $all === [] ? 0.0 : (float) min($all)];
    }

    /**
     * @param  array<string, mixed>  $trend
     * @param  array{current: array{sessions: int, key_events: float}, previous: array{sessions: int, key_events: float}}|null  $organic
     * @return list<array{label: string, value: int|float|null, rate: ?float, note: string}>
     */
    private function funnel(array $trend, ?array $organic): array
    {
        $c = $trend['current'];
        $impressions = $trend['has_gsc'] ? $c['impressions'] : null;
        $clicks = $trend['has_gsc'] ? $c['clicks'] : null;
        $sessions = $organic['current']['sessions'] ?? null;
        $conversions = $organic['current']['key_events'] ?? null;
        $rate = fn (int|float|null $a, int|float|null $b): ?float => $a !== null && $b !== null && $b > 0 ? round($a / $b * 100, 1) : null;

        return [
            ['label' => 'Gösterim', 'value' => $impressions, 'rate' => null, 'note' => 'Google aramada görünme'],
            ['label' => 'Tıklama', 'value' => $clicks, 'rate' => $rate($clicks, $impressions), 'note' => 'tıklama oranı'],
            ['label' => 'Organik oturum', 'value' => $sessions, 'rate' => $rate($sessions, $clicks), 'note' => 'GA4 · google / organic'],
            ['label' => 'Dönüşüm', 'value' => $conversions, 'rate' => $rate($conversions, $sessions), 'note' => 'organik oturumdan'],
        ];
    }

    /**
     * Four findings, each from stored numbers only (null when its source is missing).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $trend
     * @return list<array{key: string, title: string, value: string, text: string, tone: string, items: list<string>, tab: ?string}>
     */
    private function insights(DigitalAsset $site, int $days, array $rows, SiteAnalysisReader $analysis, array $trend): array
    {
        $num = fn ($v): string => number_format((float) $v, 0, ',', '.');
        $out = [];

        $hidden = array_values(array_filter($rows, fn (array $r): bool => $r['type'] === 'hizmet' && $r['impressions'] >= self::VISIBLE_IMPRESSIONS && $r['clicks'] === 0));
        usort($hidden, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        $out[] = ['key' => 'service_no_click', 'title' => 'Görünen ama tıklanmayan hizmet sayfaları', 'value' => $trend['has_gsc'] ? (string) count($hidden) : '—',
            'text' => $trend['has_gsc'] ? 'En az '.self::VISIBLE_IMPRESSIONS.' gösterim alıp hiç tıklanmayan hizmet sayfası. Başlık ve açıklama aramayla uyuşmuyor olabilir.' : 'Search Console bağlı değil.',
            'tone' => $hidden !== [] ? 'warn' : 'ok', 'items' => array_map(fn (array $r): string => $r['path'].' · '.$num($r['impressions']).' gösterim', array_slice($hidden, 0, 3)), 'tab' => 'sayfalar'];

        $queries = array_values(array_filter($analysis->queries($site, $days), fn (array $q): bool => $q['position'] !== null && $q['position'] <= 10 && $q['clicks'] === 0 && $q['impressions'] > 0));
        usort($queries, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        $out[] = ['key' => 'top10_no_click', 'title' => 'İlk 10’da olup tıklanmayan sorgular', 'value' => $trend['has_gsc'] ? (string) count($queries) : '—',
            'text' => $trend['has_gsc'] ? 'İlk sayfada görünüyor ama tıklama almıyor: sonuç metni (başlık, açıklama) iyileştirilebilir.' : 'Search Console bağlı değil.',
            'tone' => $queries !== [] ? 'warn' : 'ok', 'items' => array_map(fn (array $q): string => '«'.$q['query'].'» · sıra '.number_format((float) $q['position'], 1, ',', '.'), array_slice($queries, 0, 3)), 'tab' => 'sorgular'];

        $sessions = array_sum(array_column($rows, 'sessions'));
        $home = array_sum(array_map(fn (array $r): int => $r['path'] === '/' ? (int) $r['sessions'] : 0, $rows));
        $share = $sessions > 0 ? round($home / $sessions * 100) : null;
        $out[] = ['key' => 'home_share', 'title' => 'Ana sayfanın oturum payı', 'value' => $share !== null ? '%'.$share : '—',
            'text' => $share === null ? 'GA4 sayfa verisi yok.' : ($share >= 60 ? 'Ziyaretçilerin çoğu ana sayfadan giriyor; hizmet sayfaları aramada yeterince karşılamıyor.' : 'Girişler sayfalara dağılmış.'),
            'tone' => $share !== null && $share >= 60 ? 'warn' : 'ok', 'items' => [], 'tab' => null];

        $c = $trend['current'];
        $p = $trend['previous'];
        $rate = $trend['has_ga4'] && $c['sessions'] > 0 ? round($c['key_events'] / $c['sessions'] * 100, 2) : null;
        $prevRate = $trend['has_ga4'] && $p['sessions'] > 0 ? round($p['key_events'] / $p['sessions'] * 100, 2) : null;
        $out[] = ['key' => 'conversion_rate', 'title' => 'Dönüşüm oranı', 'value' => $rate !== null ? '%'.number_format($rate, 2, ',', '.') : '—',
            'text' => $rate === null ? 'GA4 bağlı değil ya da oturum yok.' : ('Oturum başına dönüşüm'.($prevRate !== null ? ' · önceki dönem %'.number_format($prevRate, 2, ',', '.') : '').'.'),
            'tone' => $rate !== null && $prevRate !== null && $rate < $prevRate ? 'warn' : 'ok', 'items' => [], 'tab' => 'donusumler'];

        return $out;
    }

    /**
     * Sayfa karnesi: pages with data by impressions, with a rule-based diagnosis.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function scorecard(array $rows, int $limit): array
    {
        $rows = array_values(array_filter($rows, fn (array $r): bool => $r['impressions'] > 0 || $r['sessions'] > 0));
        usort($rows, fn (array $a, array $b): int => [$b['impressions'], $b['sessions'], $a['path']] <=> [$a['impressions'], $a['sessions'], $b['path']]);

        return array_map(function (array $r): array {
            $ctr = $r['impressions'] > 0 ? round($r['clicks'] / $r['impressions'] * 100, 1) : null;

            return $r + ['ctr' => $ctr, 'diagnosis' => self::diagnosis($r, $ctr)];
        }, array_slice($rows, 0, $limit));
    }

    /** @param  array<string, mixed>  $r */
    private static function diagnosis(array $r, ?float $ctr): string
    {
        return match (true) {
            ($r['status_code'] ?? null) !== null && $r['status_code'] >= 400 => 'Sayfa hata veriyor (HTTP '.$r['status_code'].')',
            ($r['indexable'] ?? null) === false => 'noindex: Google’a kapalı',
            $r['delta'] !== null && $r['delta'] <= -30 => 'Tıklama düşüyor',
            $r['position'] !== null && $r['position'] <= 10 && $ctr !== null && $ctr < 1 && $r['impressions'] >= 100 => 'İlk sayfada ama tıklanmıyor: başlık / açıklama',
            $r['position'] !== null && $r['position'] > 10 && $r['impressions'] >= 100 => '2. sayfa ve sonrası: içerik güçlendirilmeli',
            $r['sessions'] >= 20 && (float) $r['key_events'] === 0.0 => 'Trafik var, dönüşüm yok',
            $r['impressions'] === 0 && $r['sessions'] > 0 => 'Aramada görünmüyor (yalnız diğer kanallar)',
            default => 'İyi',
        };
    }
}
