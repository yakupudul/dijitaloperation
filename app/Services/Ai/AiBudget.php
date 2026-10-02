<?php

namespace App\Services\Ai;

use App\Models\AgencySetting;
use App\Services\AiJobs\AiJobTracker;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Support\Facades\Context;
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
            ? AgencySetting::query()->orderBy('id')->value('ai_monthly_budget_usd')
            : null;

        return $stored !== null ? (float) $stored : (float) config('moxdop-ai-pricing.monthly_budget_usd', 100);
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

    /**
     * A step may run when budget remains, or when its model is known to be free. Automatic work (nobody clicked) also
     * stops for the day once its rolling 24-hour spend reached the daily ceiling.
     */
    public function allows(string $provider, string $model): bool
    {
        if ($this->pricing->isFree($provider, $model)) {
            return true;
        }

        return ! $this->isExhausted() && ! (self::isAutomatic() && $this->dailyAutoExhausted());
    }

    public function dailyAutoBudget(): float
    {
        $stored = Schema::hasColumn('agency_settings', 'ai_daily_auto_budget_usd')
            ? AgencySetting::query()->orderBy('id')->value('ai_daily_auto_budget_usd')
            : null;

        return $stored !== null ? (float) $stored : (float) config('moxdop-ai-pricing.daily_auto_budget_usd', 1);
    }

    /** Spend of the AI calls nobody clicked in the last 24 hours. */
    public function dailyAutoSpend(): float
    {
        if (! Schema::hasTable('ai_live_operations')) {
            return 0.0;
        }

        return (float) DB::table('ai_live_operations')->where('kind', 'call')->whereNull('user_id')
            ->where('started_at', '>=', now()->subDay())->sum('cost_usd');
    }

    public function dailyAutoExhausted(): bool
    {
        $budget = $this->dailyAutoBudget();

        return $budget > 0 && $this->dailyAutoSpend() >= $budget;
    }

    /**
     * Where the money went: AI calls of the last $hours per operation (most expensive first), split into automatic
     * work (nobody clicked) and operator clicks, with the model used most.
     *
     * @return list<array{operation: string, label: string, calls: int, cost: float, auto_cost: float, model: ?string}>
     */
    public function breakdown(int $hours = 24): array
    {
        if (! Schema::hasTable('ai_live_operations')) {
            return [];
        }

        return DB::table('ai_live_operations')->where('kind', 'call')->where('started_at', '>=', now()->subHours($hours))
            ->selectRaw('operation, count(*) as calls, coalesce(sum(cost_usd), 0) as cost, coalesce(sum(case when user_id is null then cost_usd else 0 end), 0) as auto_cost, max(model) as model')
            ->groupBy('operation')->orderByDesc('cost')->get()
            ->map(fn (object $row): array => ['operation' => (string) $row->operation, 'label' => AiOperationLabels::for($row->operation !== null ? (string) $row->operation : null),
                'calls' => (int) $row->calls, 'cost' => round((float) $row->cost, 4), 'auto_cost' => round((float) $row->auto_cost, 4),
                'model' => $row->model !== null ? (string) $row->model : null])
            ->all();
    }

    /** No operator behind this call: not a web request of a signed-in user and no operator handed down to the job. */
    public static function isAutomatic(): bool
    {
        if (auth()->check()) {
            return false;
        }
        if (Context::getHidden(AiLiveOperations::USER_CONTEXT) !== null) {
            return false;
        }
        $rowId = app(AiJobTracker::class)->currentRowId();

        return $rowId === null || DB::table('ai_live_operations')->where('id', $rowId)->value('user_id') === null;
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
