<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly provider spend in USD from what the app recorded: AI calls (ai_usage_records, per provider) and
 * DataForSEO (area SERP checks, search demand enrichment runs, prospect radar runs). Google / Meta APIs have
 * no per-call price and are not listed. Figures are the app's records, not the provider invoice.
 */
final class CostReader
{
    /**
     * @return array{months: list<string>, rows: list<array{label: string, group: string, values: array<string, float>, total: float}>, totals: array<string, float>, ai_budget: ?float}
     */
    public function read(int $months = 6): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $keys[] = $start->addMonths($i)->format('Y-m');
        }
        $rows = [];

        if (Schema::hasTable('ai_usage_records')) {
            $byProvider = [];
            DB::table('ai_usage_records')->where('created_at', '>=', $start)->get(['provider', 'cost_usd', 'created_at'])
                ->each(function (object $row) use (&$byProvider): void {
                    $byProvider[(string) ($row->provider ?: 'diğer')][substr((string) $row->created_at, 0, 7)] = ($byProvider[(string) ($row->provider ?: 'diğer')][substr((string) $row->created_at, 0, 7)] ?? 0) + (float) $row->cost_usd;
                });
            ksort($byProvider);
            foreach ($byProvider as $provider => $values) {
                $rows[] = $this->row('AI · '.$provider, 'ai', $values, $keys);
            }
        }
        $dataForSeo = [
            'DataForSEO · bölge SERP kontrolleri' => ['demand_serp_checks', 'checked_at', 'cost_usd'],
            'DataForSEO · talep zenginleştirme' => ['search_demand_enrichment_runs', 'created_at', 'coalesce(reported_cost_usd, estimated_cost_usd, 0)'],
            'DataForSEO · aday müşteri radarı' => ['sales_intent_radar_runs', 'created_at', 'coalesce(reported_cost_usd, 0)'],
        ];
        foreach ($dataForSeo as $label => [$table, $dateColumn, $expression]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $values = [];
            DB::table($table)->where($dateColumn, '>=', $start)->selectRaw($dateColumn.' as at, '.$expression.' as cost')->get()
                ->each(function (object $row) use (&$values): void {
                    $values[substr((string) $row->at, 0, 7)] = ($values[substr((string) $row->at, 0, 7)] ?? 0) + (float) $row->cost;
                });
            $rows[] = $this->row($label, 'dataforseo', $values, $keys);
        }

        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = round(array_sum(array_map(fn (array $row): float => $row['values'][$key], $rows)), 4);
        }
        $budget = Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'ai_monthly_budget_usd')
            ? DB::table('agency_settings')->value('ai_monthly_budget_usd') : null;

        return ['months' => $keys, 'rows' => $rows, 'totals' => $totals, 'ai_budget' => $budget !== null ? (float) $budget : null];
    }

    /**
     * @param  array<string, float>  $values
     * @param  list<string>  $keys
     * @return array{label: string, group: string, values: array<string, float>, total: float}
     */
    private function row(string $label, string $group, array $values, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = round((float) ($values[$key] ?? 0), 4);
        }

        return ['label' => $label, 'group' => $group, 'values' => $out, 'total' => round(array_sum($out), 4)];
    }
}
