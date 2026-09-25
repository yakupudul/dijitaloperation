<?php

namespace App\Services\Brain\Methods;

use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Finds methods: inside one cohort (service × page type), which features the successful pages have and the weak ones
 * lack. Top / bottom are the score percentiles; a numeric feature's bar is the median of the top pages. A feature
 * becomes a HYPOTHESIS only with enough evidence (min pages and min brands in the cohort, a clear gap between top and
 * bottom, and at least three different successful brands having it). Aggregates only — no brand name is stored.
 * Proven (validated) or retired methods keep their status; their evidence is refreshed.
 */
final class MethodEngine
{
    /** @return int methods found or refreshed */
    public function discover(): int
    {
        $cfg = (array) config('moxdop-brain.methods');
        $period = DB::table('brain_success_snapshots')->max('period');
        if ($period === null) {
            return 0;
        }
        $rows = DB::table('brain_success_snapshots as s')
            ->where('s.period', $period)->whereNotNull('s.score')->whereNotNull('s.url')->whereNotNull('s.page_type')
            ->get(['s.service_id', 's.page_type', 's.brand_id', 's.digital_asset_id', 's.url', 's.score']);
        $features = DB::table('brain_page_features')->whereIn('digital_asset_id', $rows->pluck('digital_asset_id')->unique())->get()
            ->keyBy(fn ($f): string => $f->digital_asset_id.'|'.$f->url_key);
        $count = 0;
        foreach ($rows->groupBy(fn ($r): string => $r->service_id.'|'.$r->page_type) as $key => $group) {
            [$serviceId, $pageType] = explode('|', (string) $key);
            $pages = [];
            foreach ($group as $row) {
                $f = $features->get($row->digital_asset_id.'|'.SeoText::urlKey((string) $row->url));
                if ($f === null) {
                    continue;
                }
                $pages[] = ['brand' => (int) $row->brand_id, 'score' => (float) $row->score,
                    'features' => (array) json_decode((string) $f->features, true), 'ai' => $f->ai_features !== null ? (array) json_decode((string) $f->ai_features, true) : null];
            }
            if (count($pages) < (int) $cfg['min_pages'] || count(array_unique(array_column($pages, 'brand'))) < (int) $cfg['min_brands']) {
                continue;
            }
            $scores = array_column($pages, 'score');
            sort($scores);
            $topCut = $this->quantile($scores, (float) $cfg['top_quantile']);
            $bottomCut = $this->quantile($scores, (float) $cfg['bottom_quantile']);
            $top = array_values(array_filter($pages, fn (array $p): bool => $p['score'] >= $topCut));
            $bottom = array_values(array_filter($pages, fn (array $p): bool => $p['score'] <= $bottomCut));
            foreach (MethodCatalog::FEATURES as $feature => $def) {
                $found = $this->evaluate($feature, $def['kind'], $top, $bottom, $cfg);
                if ($found === null) {
                    continue;
                }
                $this->save((int) $serviceId, $pageType, $feature, $found, count($pages), count(array_unique(array_column($pages, 'brand'))));
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $top
     * @param  list<array<string, mixed>>  $bottom
     * @param  array<string, mixed>  $cfg
     * @return array{threshold: ?float, top_rate: float, bottom_rate: float, lift: float, top_brands: int, top_n: int, bottom_n: int}|null
     */
    private function evaluate(string $feature, string $kind, array $top, array $bottom, array $cfg): ?array
    {
        $value = fn (array $p): float|bool|null => MethodCatalog::value($feature, $p['features'], $p['ai']);
        $topValues = array_values(array_filter(array_map($value, $top), fn ($v): bool => $v !== null));
        $bottomValues = array_values(array_filter(array_map($value, $bottom), fn ($v): bool => $v !== null));
        if (count($topValues) < 3 || count($bottomValues) < 3) {
            return null;
        }
        $threshold = null;
        if ($kind === 'num') {
            $sorted = $topValues;
            sort($sorted);
            $threshold = $this->quantile($sorted, 0.5);
            if ($threshold <= 0) {
                return null;
            }
        }
        $has = fn ($v): bool => $kind === 'bool' ? (bool) $v : (float) $v >= $threshold;
        $topRate = count(array_filter($topValues, $has)) / count($topValues);
        $bottomRate = count(array_filter($bottomValues, $has)) / count($bottomValues);
        $topBrands = count(array_unique(array_map(fn (array $p): int => $p['brand'], array_filter($top, fn (array $p): bool => ($v = $value($p)) !== null && $has($v)))));
        if ($topRate < (float) $cfg['min_top_rate'] || $topRate - $bottomRate < (float) $cfg['min_lift'] || $topBrands < 3) {
            return null;
        }

        return ['threshold' => $threshold, 'top_rate' => round($topRate, 3), 'bottom_rate' => round($bottomRate, 3), 'lift' => round($topRate - $bottomRate, 3),
            'top_brands' => $topBrands, 'top_n' => count($topValues), 'bottom_n' => count($bottomValues)];
    }

    /** @param  array<string, mixed>  $found */
    private function save(int $serviceId, string $pageType, string $feature, array $found, int $pages, int $brands): void
    {
        $def = MethodCatalog::FEATURES[$feature];
        $existing = DB::table('brain_methods')->where('service_id', $serviceId)->where('page_type', $pageType)->where('channel', 'website')->where('feature', $feature)->first();
        $values = [
            'label' => MethodCatalog::text($def['label'], $found['threshold'], $feature),
            'threshold' => $found['threshold'],
            'evidence' => json_encode($found + ['pages' => $pages, 'brands' => $brands]),
            'updated_at' => now(),
        ];
        if ($existing !== null) {
            DB::table('brain_methods')->where('id', $existing->id)->update($values);

            return;
        }
        DB::table('brain_methods')->insert($values + [
            'service_id' => $serviceId, 'page_type' => $pageType, 'channel' => 'website', 'feature' => $feature,
            'status' => 'hypothesis', 'discovered_at' => now(), 'created_at' => now(),
        ]);
    }

    /** @param  list<float>  $sorted */
    private function quantile(array $sorted, float $q): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $pos = ($q) * (count($sorted) - 1);
        $low = (int) floor($pos);
        $high = (int) ceil($pos);

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($pos - $low);
    }
}
