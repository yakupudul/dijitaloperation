<?php

namespace App\Services\Prompts;

use Illuminate\Support\Facades\DB;

/**
 * Run history of the AI operations from ai_usage_records: a run belongs to the operation of its prompt version, or to
 * its route key when it predates Faz 8 (no prompt version).
 */
final class PromptRunStats
{
    /** @return array<string, array{runs: int, avg_ms: ?int, cost: float}> last 30 days per operation */
    public function summary(int $days = 30): array
    {
        return DB::table('ai_usage_records as r')
            ->leftJoin('prompt_versions as v', 'v.id', '=', 'r.prompt_version_id')
            ->where('r.created_at', '>=', now()->subDays($days))
            ->selectRaw('coalesce(v.operation, r.route_key) as operation, count(*) as runs, avg(r.duration_ms) as avg_ms, coalesce(sum(r.cost_usd), 0) as cost')
            ->groupByRaw('coalesce(v.operation, r.route_key)')
            ->get()
            ->filter(fn (object $row): bool => is_string($row->operation) && $row->operation !== '')
            ->mapWithKeys(fn (object $row): array => [(string) $row->operation => [
                'runs' => (int) $row->runs,
                'avg_ms' => $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
                'cost' => round((float) $row->cost, 4),
            ]])
            ->all();
    }

    /** @return list<array{at: string, version: ?int, duration_ms: ?int, cost: ?float, status: string, model: string}> newest first */
    public function recent(string $operation, int $limit = 10): array
    {
        return DB::table('ai_usage_records as r')
            ->leftJoin('prompt_versions as v', 'v.id', '=', 'r.prompt_version_id')
            ->where(fn ($query) => $query->where('v.operation', $operation)->orWhere(fn ($legacy) => $legacy->whereNull('r.prompt_version_id')->where('r.route_key', $operation)))
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)
            ->get(['r.created_at', 'v.version', 'r.duration_ms', 'r.cost_usd', 'r.status', 'r.model'])
            ->map(fn (object $row): array => [
                'at' => (string) $row->created_at,
                'version' => $row->version !== null ? (int) $row->version : null,
                'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                'cost' => $row->cost_usd !== null ? (float) $row->cost_usd : null,
                'status' => (string) ($row->status ?? 'ok'),
                'model' => (string) $row->model,
            ])->values()->all();
    }
}
