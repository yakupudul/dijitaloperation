<?php

namespace App\Services\Brain\Methods;

use App\Models\DigitalAsset;
use App\Services\Brain\Clustering\ServiceClusters;
use App\Services\Brain\ServiceSites;
use App\Services\SeoTasks\SeoPlanInputCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Measures what an applied ("Yapıldı") recommendation did, 28 and 56 days later, against CONTROLS — the same topic on
 * other brands' sites that did not get this recommendation (difference in differences): a seasonal rise that lifts
 * everyone is not credited to the change. Website: Search Console clicks of the cluster on the site. Google Ads: the
 * ad group's conversions, against the account's other ad groups. Stored data only.
 */
final class OutcomeMeasurer
{
    public function __construct(
        private readonly SeoPlanInputCollector $collector,
        private readonly ServiceClusters $clusters,
        private readonly ServiceSites $sites,
    ) {}

    /** @return int outcomes written */
    public function measureDue(): int
    {
        $cfg = (array) config('moxdop-brain.measure');
        $lag = (int) $cfg['gsc_lag_days'];
        $count = 0;
        $due = DB::table('brain_recommendations')->where('status', 'done')->whereNotNull('resolved_at')->whereIn('channel', ['website', 'google_ads'])
            ->where('resolved_at', '<=', now()->subDays((int) $cfg['after_days'] + $lag))->orderBy('id')->limit(500)->get();
        foreach ($due as $rec) {
            $outcome = $rec->outcome !== null ? (array) json_decode((string) $rec->outcome, true) : [];
            $resolved = CarbonImmutable::parse($rec->resolved_at)->startOfDay();
            foreach (['d28' => (int) $cfg['after_days'], 'd56' => (int) $cfg['second_after_days']] as $key => $days) {
                if (isset($outcome[$key]) || $resolved->addDays($days + $lag)->isFuture()) {
                    continue;
                }
                try {
                    $result = $rec->channel === 'website' ? $this->website($rec, $resolved, $days) : $this->ads($rec, $resolved, $days);
                } catch (Throwable $exception) {
                    report($exception);
                    $result = null;
                }
                $outcome[$key] = $result ?? ['status' => 'not_measured'];
                $count++;
            }
            $lastKey = ($outcome['d56']['status'] ?? null) === 'measured' ? 'd56' : 'd28';
            $last = $outcome[$lastKey] ?? null;
            if (is_array($last) && ($last['status'] ?? null) === 'measured') {
                $outcome['summary'] = sprintf('%d. gün: %s %+.0f%%, benzer sayfalar %+.0f%% → etki %+.0f puan', $lastKey === 'd56' ? 56 : 28,
                    $last['metric_label'], $last['change'] * 100, $last['control_change'] * 100, $last['effect'] * 100);
            }
            DB::table('brain_recommendations')->where('id', $rec->id)->update(['outcome' => json_encode($outcome, JSON_UNESCAPED_UNICODE), 'measured_at' => now(), 'updated_at' => now()]);
        }

        return $count;
    }

    /** @return array<string, mixed>|null */
    private function website(object $rec, CarbonImmutable $resolved, int $days): ?array
    {
        $cluster = $rec->cluster_id !== null ? ($this->clusters->all()[(int) $rec->cluster_id] ?? null) : null;
        $site = DigitalAsset::query()->find($rec->digital_asset_id);
        if ($cluster === null || $site === null) {
            return null;
        }
        $windows = $this->windows($resolved, $days);
        $treated = $this->clicks($site, $cluster['keys'], $windows);
        $treatedSites = DB::table('brain_recommendations')->where('type', $rec->type)->where('cluster_id', $rec->cluster_id)->where('status', 'done')->pluck('digital_asset_id')->all();
        $controls = [];
        foreach ($this->sites->websites((int) $cluster['service_id']) as $other) {
            if ($other->id === $site->id || in_array($other->id, $treatedSites, true)) {
                continue;
            }
            [$before, $after] = $this->clicks($other, $cluster['keys'], $windows);
            if ($before + $after > 0) {
                $controls[] = self::change($before, $after);
            }
        }

        return $this->result('Tıklama', $treated, $controls);
    }

    /** @return array<string, mixed>|null */
    private function ads(object $rec, CarbonImmutable $resolved, int $days): ?array
    {
        $adGroup = (string) (json_decode((string) $rec->evidence, true)['ad_group_id'] ?? '');
        if ($adGroup === '') {
            return null;
        }
        $windows = $this->windows($resolved, $days);
        $sum = function (array $window, bool $treated) use ($rec, $adGroup): float {
            return (float) DB::table('google_ads_keyword_daily')->where('digital_asset_id', $rec->digital_asset_id)
                ->whereBetween('reporting_date', $window)->where('ad_group_id', $treated ? '=' : '!=', $adGroup)->sum('conversions');
        };
        $treated = [$sum($windows[0], true), $sum($windows[1], true)];
        $control = [$sum($windows[0], false), $sum($windows[1], false)];

        return $this->result('Dönüşüm', $treated, $control[0] + $control[1] > 0 ? [self::change($control[0], $control[1])] : []);
    }

    /**
     * @param  array{0: float|int, 1: float|int}  $treated
     * @param  list<float>  $controls
     * @return array<string, mixed>
     */
    private function result(string $label, array $treated, array $controls): array
    {
        if ($treated[0] + $treated[1] <= 0) {
            return ['status' => 'no_data'];
        }
        sort($controls);
        $control = $controls === [] ? 0.0 : $controls[intdiv(count($controls), 2)];
        $change = self::change($treated[0], $treated[1]);

        return ['status' => 'measured', 'metric_label' => $label, 'before' => $treated[0], 'after' => $treated[1], 'change' => round($change, 4),
            'control_change' => round($control, 4), 'controls' => count($controls), 'effect' => round($change - $control, 4)];
    }

    /** Relative change with +1 smoothing so small numbers do not explode. */
    public static function change(float|int $before, float|int $after): float
    {
        return ($after + 1) / ($before + 1) - 1;
    }

    /** @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}} before / after windows of equal length */
    private function windows(CarbonImmutable $resolved, int $days): array
    {
        return [
            [$resolved->subDays(28)->toDateString(), $resolved->subDay()->toDateString()],
            [$resolved->addDays($days - 28)->toDateString(), $resolved->addDays($days - 1)->toDateString()],
        ];
    }

    /**
     * @param  array<string, true>  $keys
     * @param  array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}  $windows
     * @return array{0: int, 1: int}
     */
    private function clicks(DigitalAsset $site, array $keys, array $windows): array
    {
        $out = [];
        foreach ($windows as [$from, $to]) {
            $rows = $this->collector->gsc($site, CarbonImmutable::parse($from), CarbonImmutable::parse($to))['rows'];
            $out[] = (int) array_sum(array_column(ServiceClusters::pages($rows, $keys), 'clicks'));
        }

        return [$out[0], $out[1]];
    }
}
