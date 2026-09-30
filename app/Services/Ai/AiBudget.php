<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly AI spend report. There is no spending limit: every configured model always runs; spend is only shown.
 */
final class AiBudget
{
    public function monthSpend(): float
    {
        if (! Schema::hasTable('ai_usage_records')) {
            return 0.0;
        }

        return (float) DB::table('ai_usage_records')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    /**
     * @return array{spend: float, calls: int, unknown_cost_calls: int, by_route: list<array{route_key: ?string, calls: int, cost: float, input_tokens: int, output_tokens: int}>}
     */
    public function monthSummary(): array
    {
        $empty = ['spend' => $this->monthSpend(), 'calls' => 0, 'unknown_cost_calls' => 0, 'by_route' => []];
        if (! Schema::hasTable('ai_usage_records')) {
            return $empty;
        }
        $base = DB::table('ai_usage_records')->where('created_at', '>=', now()->startOfMonth());
        $byRoute = (clone $base)
            ->selectRaw('route_key, count(*) as calls, coalesce(sum(cost_usd), 0) as cost, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
            ->groupBy('route_key')
            ->orderByDesc('cost')
            ->get()
            ->map(static fn (object $row): array => [
                'route_key' => $row->route_key,
                'calls' => (int) $row->calls,
                'cost' => round((float) $row->cost, 4),
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
            ])
            ->all();

        return array_merge($empty, [
            'calls' => (clone $base)->count(),
            'unknown_cost_calls' => (clone $base)->whereNull('cost_usd')->count(),
            'by_route' => $byRoute,
        ]);
    }
}
