<?php

namespace App\Services\Ai;

use App\Models\AgencySetting;
use App\Services\AiJobs\AiJobTracker;
use App\Services\AiTasks\AiTaskQueue;
use App\Support\Ai\AiOperationLabels;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AI spend guard: the OpenAI free sharing quota first (OpenAiFreeQuota), then a daily ceiling for all paid AI of the day
 * (default 1 $), the monthly budget, and automatic work (nobody
 * clicked) only in the areas allowed to run by themselves (Sorgular) or delegated to Claude. Checked when a route is resolved and again right
 * before every agent call (AiLiveOperations::started), so no paid call starts past the ceiling.
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
     * A step may run when: nobody clicked → only in an area allowed to run by itself (Sorgular); the model is free, or
     * the month's budget and the day's ceiling (all AI of the day) still have room.
     */
    public function allows(string $provider, string $model, ?string $operation = null): bool
    {
        return $this->blockReason($provider, $model, $operation) === null;
    }

    /** Why an AI call must not start (Turkish, for the operator), null when it may. */
    public function blockReason(?string $provider, ?string $model, ?string $operation): ?string
    {
        if (self::isAutomatic() && ! self::automaticAllowed($operation)) {
            return 'Bu AI işi yalnız operatör tıklayınca çalışır (otomatik çalışan alan: Sorgular).';
        }
        if ($provider !== null && $model !== null && $provider !== '' && $model !== '' && $this->pricing->isFree($provider, $model)) {
            return null;
        }
        // Önce ücretsiz kota, sonra günlük tavan: free OpenAI tokens left today → the call costs nothing.
        if (app(OpenAiFreeQuota::class)->hasRoom($provider, $model)) {
            return null;
        }
        if ($this->dailyExhausted()) {
            return sprintf('Günlük AI tavanı doldu ($%.2f / $%.2f); yarın yeniden çalışır.', $this->dailySpend(), $this->dailyBudget());
        }
        if ($this->isExhausted()) {
            return 'Aylık AI bütçesi doldu.';
        }

        return null;
    }

    /**
     * Scheduled gates that are not an AI operation themselves => the operations they run. The gate opens when one of
     * them is delegated to Claude; the others are still stopped call by call (blockReason).
     */
    private const array GATES = [
        'site.weekly_refresh' => [AiRouteKeys::SITE_PAGE_CATEGORIES, AiRouteKeys::SITE_SERVICE_PAGES, AiRouteKeys::SITE_CLUSTER_PAGES, AiRouteKeys::SITE_CLUSTER_MATCH],
    ];

    /**
     * Whether AI of this operation may run with nobody clicking: an area of config moxdop-ai-pricing.automatic_areas, or
     * an operation delegated to Claude over MCP (no API cost; yakup, 2026-10-03).
     */
    public static function automaticAllowed(?string $operation): bool
    {
        $areas = (array) config('moxdop-ai-pricing.automatic_areas', ['queries']);
        if (in_array('*', $areas, true)) {
            return true;
        }
        if ($operation === null) {
            return false;
        }
        if (in_array(explode('.', $operation)[0], $areas, true)) {
            return true;
        }

        return self::delegatedToClaude(self::GATES[$operation] ?? [$operation]);
    }

    /** @param  list<string>  $operations */
    private static function delegatedToClaude(array $operations): bool
    {
        try {
            $queue = app(AiTaskQueue::class);
            foreach ($operations as $operation) {
                if ($queue->delegated($operation)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function dailyBudget(): float
    {
        $stored = Schema::hasColumn('agency_settings', 'ai_daily_auto_budget_usd')
            ? AgencySetting::query()->orderBy('id')->value('ai_daily_auto_budget_usd')
            : null;

        return $stored !== null ? (float) $stored : (float) config('moxdop-ai-pricing.daily_auto_budget_usd', 1);
    }

    /** Spend of every AI call today (Europe/Istanbul): the largest of the call list, the usage records and OpenAI's audited cost. */
    public function dailySpend(): float
    {
        $since = now('Europe/Istanbul')->startOfDay()->utc();
        $calls = Schema::hasTable('ai_live_operations')
            ? (float) DB::table('ai_live_operations')->where('kind', 'call')->where('started_at', '>=', $since)->sum('cost_usd') : 0.0;
        $records = Schema::hasTable('ai_usage_records')
            ? (float) DB::table('ai_usage_records')->where('created_at', '>=', $since)->sum('cost_usd') : 0.0;

        // OpenAI's real cost of today (Kota denetimi) is a floor: our estimate never hides real spend.
        return max($calls, $records, (float) OpenAiCostAudit::todayActual());
    }

    public function dailyExhausted(): bool
    {
        $budget = $this->dailyBudget();

        return $budget > 0 && $this->dailySpend() >= $budget;
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
