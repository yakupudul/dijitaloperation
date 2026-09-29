<?php

namespace App\Services\Retention;

use App\Services\DataPool\Compact\CompactFactStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps stored data lean (MoxDOP v2 Faz 1, `moxdop:retention`):
 * - daily facts older than 16 months are rolled into performance_monthly_rollups, then deleted;
 * - query daily facts (Search Console query × page, Google Ads search terms) older than 16 months are deleted —
 *   their monthly form is `query_sources`, itself kept 24 months;
 * - raw provider payloads / HTML copies older than the window are deleted (each page's latest HTML is kept);
 * - telemetry tables are trimmed to their own windows.
 */
final class DataRetentionService
{
    /** @var array<string, array{dimensions: list<string>, sums: list<string>, averages: list<string>}> */
    private array $plans = [];

    /**
     * @return array{raw_objects: int, telemetry: array<string, int>, rolled_rows: int, rollup_rows: int, query_daily_rows: array<string, int>, query_source_rows: int}
     */
    public function run(bool $dryRun = false): array
    {
        return [
            'raw_objects' => $this->purgeAllRawPayloads($dryRun),
            'telemetry' => $this->purgeTelemetry($dryRun),
            ...$this->rollupDailyPerformance($dryRun),
            'query_daily_rows' => $this->purgeQueryDailyFacts($dryRun),
            'query_source_rows' => $this->purgeQuerySources($dryRun),
        ];
    }

    /** First month that stays daily (older months are rolled / deleted). */
    public function dailyCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth()->subMonths(max(1, (int) config('moxdop-retention.daily_performance_months', 16)));
    }

    /** Raw payloads in batches until nothing is left over the window (bounded). */
    public function purgeAllRawPayloads(bool $dryRun = false): int
    {
        if ($dryRun) {
            return $this->purgeRawPayloads(true);
        }
        $total = 0;
        for ($batch = 0; $batch < 500; $batch++) {
            $deleted = $this->purgeRawPayloads();
            $total += $deleted;
            if ($deleted < max(1, (int) config('moxdop-retention.raw_payload_batch', 2000))) {
                break;
            }
        }

        return $total;
    }

    /**
     * Search Console query × page and Google Ads search terms older than the daily window (their monthly aggregate is
     * query_sources). Compact tables delete from their fact table.
     *
     * @return array<string, int>
     */
    public function purgeQueryDailyFacts(bool $dryRun = false): array
    {
        $cutoff = $this->dailyCutoff()->toDateString();
        $compact = app(CompactFactStore::class);
        $result = [];
        foreach ((array) config('moxdop-retention.query_daily_tables', []) as $table) {
            if (! Schema::hasTable($table) && ! $compact->isCompact($table)) {
                continue;
            }
            $target = $compact->isCompact($table) ? (string) $compact->spec($table)['fact'] : $table;
            $query = DB::table($target)->where('reporting_date', '<', $cutoff);
            $result[$table] = $dryRun ? $query->count() : $query->delete();
        }

        return $result;
    }

    /** query_sources months older than the query window (24 months). */
    public function purgeQuerySources(bool $dryRun = false): int
    {
        if (! Schema::hasTable('query_sources')) {
            return 0;
        }
        $cutoff = CarbonImmutable::now()->startOfMonth()->subMonths(max(1, (int) config('moxdop-retention.query_sources_months', 24)));
        $query = DB::table('query_sources')->where('month', '<', $cutoff->toDateString());

        return $dryRun ? $query->count() : $query->delete();
    }

    public function purgeRawPayloads(bool $dryRun = false): int
    {
        if (! Schema::hasTable('raw_ingestion_objects')) {
            return 0;
        }
        $cutoff = now()->subDays(max(30, (int) config('moxdop-retention.raw_payload_days', 90)));
        $query = DB::table('raw_ingestion_objects as o')
            ->where('o.captured_at', '<', $cutoff)
            ->whereNotIn('o.id', $this->protectedRawObjectIds($cutoff))
            ->orderBy('o.id')
            ->limit(max(1, (int) config('moxdop-retention.raw_payload_batch', 2000)));

        if ($dryRun) {
            return (clone $query)->count();
        }

        $deleted = 0;
        foreach ($query->get(['o.id', 'o.storage_disk', 'o.object_key']) as $object) {
            try {
                Storage::disk((string) $object->storage_disk)->delete((string) $object->object_key);
            } catch (Throwable $error) {
                report($error);

                continue;
            }
            $deleted += DB::table('raw_ingestion_objects')->where('id', $object->id)->delete();
        }

        return $deleted;
    }

    /**
     * @return array<string, int>
     */
    public function purgeTelemetry(bool $dryRun = false): array
    {
        $result = [];
        foreach ((array) config('moxdop-retention.telemetry', []) as $table => $rule) {
            [$column, $days] = $rule;
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $query = DB::table($table)->where($column, '<', now()->subDays(max(1, (int) $days)));
            if (isset($rule[2])) {
                $query->where(...$rule[2]);
            }
            $result[$table] = $dryRun ? $query->count() : $query->delete();
        }

        return $result;
    }

    /**
     * @return array{rolled_rows: int, rollup_rows: int}
     */
    public function rollupDailyPerformance(bool $dryRun = false): array
    {
        $cutoff = $this->dailyCutoff();
        $totals = ['rolled_rows' => 0, 'rollup_rows' => 0];
        if (! Schema::hasTable('performance_monthly_rollups')) {
            return $totals;
        }

        foreach ($this->dailyPerformanceTables() as $table) {
            $months = DB::table($table)->where('reporting_date', '<', $cutoff->toDateString())
                ->selectRaw('min(reporting_date) as first_day')->value('first_day');
            if ($months === null) {
                continue;
            }
            for ($month = CarbonImmutable::parse($months)->startOfMonth(); $month->lt($cutoff); $month = $month->addMonth()) {
                $counts = $this->rollupMonth($table, $month, $dryRun);
                $totals['rolled_rows'] += $counts['rolled_rows'];
                $totals['rollup_rows'] += $counts['rollup_rows'];
            }
        }

        return $totals;
    }

    /**
     * @return array{rolled_rows: int, rollup_rows: int}
     */
    public function rollupMonth(string $table, CarbonImmutable $month, bool $dryRun = false): array
    {
        $plan = $this->plan($table);
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $base = DB::table($table)->whereBetween('reporting_date', [$from, $to]);
        $rows = (clone $base)->count();
        if ($rows === 0 || ($plan['sums'] === [] && $plan['averages'] === [])) {
            return ['rolled_rows' => 0, 'rollup_rows' => 0];
        }

        $weight = collect((array) config('moxdop-retention.average_weight_columns', []))
            ->first(fn (string $column): bool => in_array($column, $plan['sums'], true));
        $select = array_map(fn (string $column): string => $this->quote($column), $plan['dimensions']);
        foreach ($plan['sums'] as $column) {
            $select[] = 'sum('.$this->quote($column).') as '.$this->quote('s__'.$column);
        }
        foreach ($plan['averages'] as $column) {
            $select[] = $weight !== null
                ? 'sum('.$this->quote($column).' * '.$this->quote($weight).') as '.$this->quote('w__'.$column)
                : 'avg('.$this->quote($column).') as '.$this->quote('a__'.$column);
        }
        $select[] = 'count(distinct reporting_date) as '.$this->quote('d__days');
        $select[] = 'count(*) as '.$this->quote('d__rows');

        $groups = (clone $base)->selectRaw(implode(', ', $select))->groupBy($plan['dimensions'])->get();
        if ($dryRun) {
            return ['rolled_rows' => $rows, 'rollup_rows' => $groups->count()];
        }

        DB::transaction(function () use ($groups, $plan, $table, $from, $to, $weight, $base): void {
            foreach ($groups as $group) {
                $group = (array) $group;
                $dimensions = [];
                foreach ($plan['dimensions'] as $column) {
                    $dimensions[$column] = $group[$column] ?? null;
                }
                $metrics = [];
                foreach ($plan['sums'] as $column) {
                    $metrics[$column] = $this->number($group['s__'.$column] ?? null);
                }
                foreach ($plan['averages'] as $column) {
                    $metrics[$column] = $weight !== null
                        ? (($total = (float) ($metrics[$weight] ?? 0)) > 0 ? round((float) $group['w__'.$column] / $total, 6) : null)
                        : $this->number($group['a__'.$column] ?? null);
                }
                $this->storeRollup($table, $from, $dimensions, $metrics, (int) $group['d__days'], (int) $group['d__rows']);
            }
            // A compact table is a view; its rows live in the fact table (same reporting_date column).
            $compact = app(CompactFactStore::class);
            if ($compact->isCompact($table)) {
                DB::table((string) $compact->spec($table)['fact'])->whereBetween('reporting_date', [$from, $to])->delete();
            } else {
                (clone $base)->delete();
            }
        });

        return ['rolled_rows' => $rows, 'rollup_rows' => $groups->count()];
    }

    /**
     * Daily performance tables: every `*_daily` table with a reporting_date column, minus the query daily facts.
     *
     * @return list<string>
     */
    public function dailyPerformanceTables(): array
    {
        $gold = (array) config('moxdop-retention.query_daily_tables', []);
        $compact = app(CompactFactStore::class);
        $views = collect([...array_keys((array) config('moxdop-compact-facts.tables')), ...array_keys((array) config('moxdop-compact-facts.generic'))])
            ->filter(fn (string $table): bool => $compact->isCompact($table));

        return collect(Schema::getTableListing(schemaQualified: false))
            ->merge($views)
            ->map(fn (string $table): string => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)
            ->filter(fn (string $table): bool => str_ends_with($table, '_daily') && ! in_array($table, $gold, true))
            ->filter(fn (string $table): bool => Schema::hasColumn($table, 'reporting_date'))
            ->unique()->sort()->values()->all();
    }

    /**
     * @return array{dimensions: list<string>, sums: list<string>, averages: list<string>}
     */
    public function plan(string $table): array
    {
        if (isset($this->plans[$table])) {
            return $this->plans[$table];
        }
        $ignored = (array) config('moxdop-retention.ignored_columns', []);
        $plan = ['dimensions' => [], 'sums' => [], 'averages' => []];
        foreach (Schema::getColumns($table) as $column) {
            $name = (string) $column['name'];
            if (in_array($name, $ignored, true)) {
                continue;
            }
            $numeric = preg_match('/int|dec|num|float|double|real/i', (string) $column['type_name']) === 1;
            if (! $numeric || preg_match((string) config('moxdop-retention.dimension_numeric_pattern'), $name) === 1) {
                $plan['dimensions'][] = $name;
            } elseif (preg_match((string) config('moxdop-retention.average_column_pattern'), $name) === 1) {
                $plan['averages'][] = $name;
            } else {
                $plan['sums'][] = $name;
            }
        }

        return $this->plans[$table] = $plan;
    }

    /**
     * Raw objects that must survive: HTML copies still inside the window and the latest copy of every page.
     */
    private function protectedRawObjectIds(\DateTimeInterface $cutoff): Builder
    {
        return DB::table('website_html_snapshot as s')
            ->whereNotNull('s.raw_ingestion_object_id')
            ->where(function ($query) use ($cutoff): void {
                $query->where('s.observed_at', '>=', $cutoff)
                    ->orWhereRaw('s.observed_at = (select max(s2.observed_at) from website_html_snapshot s2 where s2.digital_asset_id = s.digital_asset_id and s2.url = s.url)');
            })
            ->select('s.raw_ingestion_object_id');
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  array<string, int|float|null>  $metrics
     */
    private function storeRollup(string $table, string $month, array $dimensions, array $metrics, int $days, int $rows): void
    {
        $hash = hash('sha256', json_encode($dimensions, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '');
        $existing = DB::table('performance_monthly_rollups')
            ->where('source_table', $table)->where('month', $month)->where('dimension_hash', $hash)->first();
        if ($existing !== null) {
            // Late rows for an already rolled month: add the sums, keep the stored averages.
            $stored = (array) json_decode((string) $existing->metrics, true);
            foreach ($this->plan($table)['sums'] as $column) {
                $stored[$column] = ($stored[$column] ?? 0) + ($metrics[$column] ?? 0);
            }
            DB::table('performance_monthly_rollups')->where('id', $existing->id)->update([
                'metrics' => json_encode($stored), 'day_count' => max((int) $existing->day_count, $days),
                'rows_rolled' => (int) $existing->rows_rolled + $rows, 'updated_at' => now(),
            ]);

            return;
        }
        DB::table('performance_monthly_rollups')->insert([
            'source_table' => $table,
            'digital_asset_id' => isset($dimensions['digital_asset_id']) ? (int) $dimensions['digital_asset_id'] : null,
            'month' => $month,
            'dimension_hash' => $hash,
            'dimensions' => json_encode($dimensions, JSON_UNESCAPED_UNICODE),
            'metrics' => json_encode($metrics),
            'day_count' => $days,
            'rows_rolled' => $rows,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function number(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        $float = (float) $value;

        return floor($float) === $float && abs($float) < PHP_INT_MAX ? (int) $float : round($float, 6);
    }

    private function quote(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }
}
