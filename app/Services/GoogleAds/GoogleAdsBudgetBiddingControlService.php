<?php

namespace App\Services\GoogleAds;

use App\Models\GoogleAdsBudgetPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Local decision layer for Budget & Bidding.
 *
 * It only uses canonical MOXDOP plan data plus already-collected Google Ads facts.
 * No provider call is made and missing targets/forecast signals are never invented.
 */
final class GoogleAdsBudgetBiddingControlService
{
    /**
     * @param list<array<string,mixed>> $campaigns
     * @param array<string,mixed> $professional
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function workspace(
        string $assetId,
        ?string $start,
        ?string $end,
        array $campaigns,
        array $professional,
        array $data,
    ): array {
        $currency = (string) ($professional['currency'] ?? data_get($data, 'identity.currency') ?? '');
        $plan = $this->plan($assetId, $start, $end);
        if ($plan instanceof GoogleAdsBudgetPlan && filled($plan->currency)) {
            $currency = (string) $plan->currency;
        }

        $rows = collect($campaigns)
            ->map(fn (array $row): array => $this->normalizeCampaign($row, $currency))
            ->values();

        $spend = (float) $rows->sum('spend');
        $conversions = (float) $rows->sum('conversions');
        $accountCpa = $conversions > 0 ? $spend / $conversions : null;
        $targetCpa = $plan !== null && is_numeric($plan->target_cpa) && (float) $plan->target_cpa > 0
            ? (float) $plan->target_cpa
            : null;
        $decisionBenchmark = $targetCpa ?? $accountCpa;
        $decisionBenchmarkSource = $targetCpa !== null ? 'plan_target_cpa' : ($accountCpa !== null ? 'account_provider_cpa' : 'unavailable');

        $decisionRows = $rows
            ->map(fn (array $row): array => $this->withDecision($row, $decisionBenchmark, $decisionBenchmarkSource))
            ->sortBy(fn (array $row): array => [$this->decisionPriority($row['decision_code']), -($row['lost_is_budget'] ?? 0), $row['cpa'] ?? PHP_FLOAT_MAX])
            ->values();

        $pacing = $this->pacing($plan, $spend, $start, $end, $data);
        $matrix = $this->matrix($decisionRows);
        $strategies = $this->strategyHealth(collect(data_get($professional, 'budget_bidding.strategies', [])), $currency);

        return [
            'currency' => $currency,
            'plan' => $plan === null ? null : [
                'id' => (int) $plan->id,
                'planned_budget' => (float) $plan->planned_budget,
                'target_cpa' => $plan->target_cpa !== null ? (float) $plan->target_cpa : null,
                'target_roas' => $plan->target_roas !== null ? (float) $plan->target_roas : null,
                'notes' => $plan->notes,
                'period_start' => $plan->period_start?->toDateString(),
                'period_end' => $plan->period_end?->toDateString(),
                'currency' => $plan->currency,
            ],
            'summary' => [
                'spend' => round($spend, 2),
                'provider_conversions' => round($conversions, 2),
                'provider_cpa' => $accountCpa !== null ? round($accountCpa, 2) : null,
                'google_daily_budget_total' => round((float) $rows->filter(fn (array $row): bool => $row['enabled'])->sum('budget'), 2),
                'campaigns' => $rows->count(),
                'enabled_campaigns' => $rows->where('enabled', true)->count(),
                'scale_candidates' => $decisionRows->where('decision_code', 'scale')->count(),
                'fix_before_scale' => $decisionRows->where('decision_code', 'fix')->count(),
                'reduce_review' => $decisionRows->where('decision_code', 'reduce')->count(),
                'rank_constraints' => $decisionRows->where('decision_code', 'rank')->count(),
                'benchmark_cpa' => $decisionBenchmark !== null ? round($decisionBenchmark, 2) : null,
                'benchmark_source' => $decisionBenchmarkSource,
            ],
            'pacing' => $pacing,
            'campaigns' => $decisionRows->all(),
            'matrix' => $matrix,
            'reallocation' => $this->reallocation($decisionRows, $plan),
            'strategies' => $strategies,
            'scenario' => [
                'available' => false,
                'reason' => 'Provider forecast / simulator data is not collected into the canonical pool yet. MOXDOP will not fabricate incremental conversions or marginal CPA.',
            ],
            'boundaries' => [
                'provider_cpa' => 'Provider CPA uses Google Ads conversions, not Qualified Lead, Business Outcome, or verified revenue.',
                'relative_benchmark' => 'When no agency target CPA exists, campaign decisions use account-level provider CPA only as a relative efficiency benchmark, not as a business target.',
                'reallocation' => 'Budget directions are decision support. No Google Ads budget or bidding strategy is changed automatically.',
            ],
        ];
    }

    private function plan(string $assetId, ?string $start, ?string $end): ?GoogleAdsBudgetPlan
    {
        if (! ctype_digit($assetId) || ! filled($start) || ! filled($end) || ! Schema::hasTable('google_ads_budget_plans')) {
            return null;
        }

        return GoogleAdsBudgetPlan::query()
            ->where('digital_asset_id', (int) $assetId)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->first();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeCampaign(array $row, string $currency): array
    {
        $spend = is_numeric($row['spend'] ?? null) ? (float) $row['spend'] : 0.0;
        $conversions = is_numeric($row['leads'] ?? null) ? (float) $row['leads'] : 0.0;
        $status = strtoupper(trim((string) ($row['status'] ?? '')));

        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? '—'),
            'type' => (string) ($row['type'] ?? '—'),
            'status' => $status !== '' ? $status : 'UNKNOWN',
            'enabled' => in_array($status, ['ENABLED', 'ACTIVE'], true),
            'budget' => is_numeric($row['budget'] ?? null) ? (float) $row['budget'] : 0.0,
            'spend' => $spend,
            'conversions' => $conversions,
            'cpa' => $conversions > 0 ? round($spend / $conversions, 2) : null,
            'impr_share' => is_numeric($row['impr_share'] ?? null) ? (float) $row['impr_share'] : null,
            'lost_is_budget' => is_numeric($row['lost_is_budget'] ?? null) ? (float) $row['lost_is_budget'] : null,
            'lost_is_rank' => is_numeric($row['lost_is_rank'] ?? null) ? (float) $row['lost_is_rank'] : null,
            'currency' => (string) ($row['currency'] ?? $currency),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function withDecision(array $row, ?float $benchmark, string $benchmarkSource): array
    {
        $cpa = $row['cpa'];
        $lostBudget = $row['lost_is_budget'];
        $lostRank = $row['lost_is_rank'];
        $ratio = $benchmark !== null && $benchmark > 0 && $cpa !== null ? $cpa / $benchmark : null;
        $budgetPressure = $lostBudget !== null && $lostBudget >= 10.0;
        $rankPressure = $lostRank !== null && $lostRank >= 15.0;

        if (! $row['enabled'] && $row['spend'] <= 0) {
            [$code, $label, $reason, $tone] = ['inactive', 'Inactive', 'Campaign is not currently active and has no spend in the selected period.', 'neutral'];
        } elseif ($cpa === null) {
            [$code, $label, $reason, $tone] = ['insufficient', 'Needs signal', 'No provider conversion CPA is available for a confident budget decision.', 'neutral'];
        } elseif ($ratio !== null && $ratio <= 1.0 && $budgetPressure) {
            [$code, $label, $reason, $tone] = ['scale', 'Scale candidate', 'Efficiency is at or better than the decision benchmark while budget loss indicates additional reachable demand.', 'positive'];
        } elseif ($ratio !== null && $ratio > 1.25 && $budgetPressure) {
            [$code, $label, $reason, $tone] = ['fix', 'Fix before scaling', 'The campaign is losing impressions to budget, but current CPA is materially worse than the benchmark.', 'warning'];
        } elseif ($ratio !== null && $ratio > 1.25) {
            [$code, $label, $reason, $tone] = ['reduce', 'Reduce / review', 'CPA is materially worse than the decision benchmark and there is no strong budget-constrained scale signal.', 'danger'];
        } elseif ($rankPressure && ! $budgetPressure) {
            [$code, $label, $reason, $tone] = ['rank', 'Rank / quality constraint', 'Lost impression share is driven more by rank than budget; increasing budget alone is unlikely to solve the constraint.', 'warning'];
        } elseif ($ratio !== null && $ratio <= 1.0) {
            [$code, $label, $reason, $tone] = ['efficient', 'Efficient / maintain', 'CPA is at or better than the benchmark, but there is no strong budget-loss signal for aggressive scaling.', 'positive'];
        } else {
            [$code, $label, $reason, $tone] = ['maintain', 'Maintain / monitor', 'No strong evidence supports a material budget move yet.', 'neutral'];
        }

        return $row + [
            'decision_code' => $code,
            'decision_label' => $label,
            'decision_reason' => $reason,
            'decision_tone' => $tone,
            'benchmark_ratio' => $ratio !== null ? round($ratio, 3) : null,
            'benchmark_source' => $benchmarkSource,
        ];
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function matrix(Collection $rows): array
    {
        return [
            'scale' => $rows->where('decision_code', 'scale')->values()->all(),
            'fix' => $rows->where('decision_code', 'fix')->values()->all(),
            'efficient' => $rows->whereIn('decision_code', ['efficient', 'maintain'])->values()->all(),
            'reduce' => $rows->whereIn('decision_code', ['reduce', 'rank'])->values()->all(),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function reallocation(Collection $rows, ?GoogleAdsBudgetPlan $plan): array
    {
        $increase = $rows->where('decision_code', 'scale')
            ->sortByDesc(fn (array $row): float => (float) ($row['lost_is_budget'] ?? 0))
            ->take(5)
            ->values()
            ->all();
        $decrease = $rows->where('decision_code', 'reduce')
            ->sortByDesc(fn (array $row): float => (float) ($row['benchmark_ratio'] ?? 0))
            ->take(5)
            ->values()
            ->all();

        return [
            'available' => $increase !== [] || $decrease !== [],
            'amounts_available' => false,
            'plan_defined' => $plan !== null && (float) $plan->planned_budget > 0,
            'increase' => $increase,
            'decrease' => $decrease,
            'note' => 'MOXDOP can identify directional transfer candidates from observed efficiency and budget pressure. Exact transfer amounts require provider forecast/simulator evidence and are intentionally not fabricated.',
        ];
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function strategyHealth(Collection $rows, string $currency): array
    {
        $items = $rows->map(function (array $row) use ($currency): array {
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $campaignCount = is_numeric($row['campaign_count'] ?? null) ? (int) $row['campaign_count'] : 0;
            $status = strtoupper((string) ($row['status'] ?? 'UNKNOWN'));
            $type = strtoupper((string) ($row['strategy_type'] ?? 'UNKNOWN'));
            $targetCpa = $this->numericOrNull($row['target_cpa'] ?? $metadata['target_cpa'] ?? null);
            $targetRoas = $this->numericOrNull($row['target_roas'] ?? $metadata['target_roas'] ?? null);

            if ($campaignCount === 0) {
                [$health, $healthLabel] = ['unused', 'Unused portfolio strategy'];
            } elseif (! in_array($status, ['ENABLED', 'ACTIVE'], true)) {
                [$health, $healthLabel] = ['attention', 'Strategy not enabled'];
            } else {
                [$health, $healthLabel] = ['active', 'Active'];
            }

            return [
                'id' => (string) ($row['bidding_strategy_id'] ?? $row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? $row['bidding_strategy_id'] ?? '—'),
                'type' => $type,
                'type_label' => $this->strategyTypeLabel($type),
                'status' => $status,
                'campaign_count' => $campaignCount,
                'target_cpa' => $targetCpa,
                'target_roas' => $targetRoas,
                'currency' => $currency,
                'health' => $health,
                'health_label' => $healthLabel,
            ];
        })->values();

        return [
            'items' => $items->all(),
            'total' => $items->count(),
            'active' => $items->where('health', 'active')->count(),
            'unused' => $items->where('health', 'unused')->count(),
            'attention' => $items->where('health', 'attention')->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function pacing(?GoogleAdsBudgetPlan $plan, float $spend, ?string $start, ?string $end, array $data): array
    {
        if ($plan === null || (float) $plan->planned_budget <= 0 || ! filled($start) || ! filled($end)) {
            return [
                'available' => false,
                'planned_budget' => null,
                'actual_spend' => round($spend, 2),
                'remaining' => null,
                'expected_spend' => null,
                'pace_percent' => null,
                'elapsed_percent' => null,
                'projected_spend' => null,
                'variance' => null,
                'state' => 'unavailable',
                'chart' => ['labels' => [], 'actual' => [], 'target' => []],
            ];
        }

        $timezone = (string) (data_get($data, 'identity.reporting_timezone') ?: config('app.timezone', 'UTC'));
        $from = CarbonImmutable::parse($start, $timezone)->startOfDay();
        $to = CarbonImmutable::parse($end, $timezone)->startOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $totalDays = max(1, $from->diffInDays($to) + 1);

        if ($today->lessThan($from)) {
            $elapsedDays = 0;
        } elseif ($today->greaterThan($to)) {
            $elapsedDays = $totalDays;
        } else {
            $elapsedDays = min($totalDays, $from->diffInDays($today) + 1);
        }

        $elapsed = $elapsedDays / $totalDays;
        $budget = (float) $plan->planned_budget;
        $expected = $budget * $elapsed;
        $pace = $expected > 0 ? ($spend / $expected) * 100 : null;
        $projected = $elapsed > 0 && $elapsed < 1 ? $spend / $elapsed : $spend;
        $variance = $projected - $budget;
        $state = $pace === null ? 'unavailable' : ($pace > 105 ? 'ahead' : ($pace < 95 ? 'behind' : 'on_track'));

        $trend = is_array($data['performance_trend'] ?? null) ? $data['performance_trend'] : [];
        $labels = is_array($trend['labels'] ?? null) ? $trend['labels'] : [];
        $dailySpend = is_array($trend['spend'] ?? null) ? $trend['spend'] : [];
        $actualSeries = [];
        $targetSeries = [];
        $cumulative = 0.0;
        $points = max(1, count($labels));
        foreach ($labels as $index => $label) {
            $cumulative += is_numeric($dailySpend[$index] ?? null) ? (float) $dailySpend[$index] : 0.0;
            $actualSeries[] = round($cumulative, 2);
            $targetSeries[] = round($budget * (($index + 1) / $points), 2);
        }

        return [
            'available' => true,
            'planned_budget' => round($budget, 2),
            'actual_spend' => round($spend, 2),
            'remaining' => round($budget - $spend, 2),
            'expected_spend' => round($expected, 2),
            'pace_percent' => $pace !== null ? round($pace, 1) : null,
            'elapsed_percent' => round($elapsed * 100, 1),
            'projected_spend' => round($projected, 2),
            'variance' => round($variance, 2),
            'state' => $state,
            'chart' => ['labels' => $labels, 'actual' => $actualSeries, 'target' => $targetSeries],
        ];
    }

    private function decisionPriority(string $code): int
    {
        return match ($code) {
            'scale' => 1,
            'fix' => 2,
            'reduce' => 3,
            'rank' => 4,
            'efficient' => 5,
            'maintain' => 6,
            'insufficient' => 7,
            default => 8,
        };
    }

    private function strategyTypeLabel(string $type): string
    {
        return match ($type) {
            'TARGET_CPA' => 'Target CPA',
            'TARGET_ROAS' => 'Target ROAS',
            'MAXIMIZE_CONVERSIONS' => 'Maximize conversions',
            'MAXIMIZE_CONVERSION_VALUE' => 'Maximize conversion value',
            'TARGET_SPEND' => 'Maximize clicks / target spend',
            'MANUAL_CPC' => 'Manual CPC',
            'TARGET_IMPRESSION_SHARE' => 'Target impression share',
            default => str_replace('_', ' ', ucfirst(strtolower($type))),
        };
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
