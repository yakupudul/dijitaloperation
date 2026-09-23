<?php

namespace App\Services\Ai;

use App\Models\AgencySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly AI spend guard. When the month's recorded spend reaches the budget, only models with a
 * known zero price stay eligible; everything else is skipped and plans continue on rules.
 */
final class AiBudget
{
    public function __construct(private readonly AiPricing $pricing) {}

    public function monthlyBudget(): float
    {
        $stored = Schema::hasColumn('agency_settings', 'ai_monthly_budget_usd')
            ? AgencySetting::query()->value('ai_monthly_budget_usd')
            : null;

        return $stored !== null ? (float) $stored : (float) config('moxdop-ai-pricing.monthly_budget_usd', 25);
    }

    public function monthSpend(): float
    {
        if (! Schema::hasTable('ai_usage_records')) {
            return 0.0;
        }

        return (float) DB::table('ai_usage_records')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    public function isExhausted(): bool
    {
        $budget = $this->monthlyBudget();

        return $budget > 0 && $this->monthSpend() >= $budget;
    }

    /** A step may run when budget remains, or when its model is known to be free. */
    public function allows(string $provider, string $model): bool
    {
        return ! $this->isExhausted() || $this->pricing->isFree($provider, $model);
    }

    /**
     * @return array{budget: float, spend: float, remaining: float, exhausted: bool, calls: int, unknown_cost_calls: int, by_route: list<array{route_key: ?string, calls: int, cost: float, input_tokens: int, output_tokens: int}>}
     */
    public function monthSummary(): array
    {
        $budget = $this->monthlyBudget();
        $spend = $this->monthSpend();
        $empty = ['budget' => $budget, 'spend' => $spend, 'remaining' => max(0.0, $budget - $spend), 'exhausted' => $this->isExhausted(), 'calls' => 0, 'unknown_cost_calls' => 0, 'by_route' => []];
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
