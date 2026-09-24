<?php

namespace App\Services\Brain;

use Illuminate\Support\Facades\DB;

/**
 * How often a rule's advice helped when it was done: measured outcomes (28 / 56 days) of done advisor items
 * and SEO tasks across the agency's brands, and how often the problem came back. Feeds the priority of new
 * items (weight 1 ± range once enough outcomes are measured) and the Yöntem Kütüphanesi.
 */
final class RuleEffectiveness
{
    /** @var array<string, array<string, array{done: int, measured: int, improved: int, reopened: int}>> sector ('' = all) => rule => stats */
    private array $stats = [];

    /**
     * @param  ?string  $sector  brand sector code; null = every brand
     * @return array<string, array{done: int, measured: int, improved: int, reopened: int}>
     */
    public function all(?string $sector = null): array
    {
        $cacheKey = (string) $sector;
        if (isset($this->stats[$cacheKey])) {
            return $this->stats[$cacheKey];
        }
        $stats = [];
        foreach (['advisor_items', 'seo_tasks'] as $table) {
            DB::table($table)->where(fn ($q) => $q->where('status', 'done')->orWhere('reopened_count', '>', 0))
                ->when($sector !== null, fn ($q) => $q->whereIn('brand_id', DB::table('brands')->where('sector', $sector)->select('id')))
                ->select(['rule_id', 'status', 'outcome', 'reopened_count'])->orderBy('id')
                ->chunk(1000, function ($rows) use (&$stats): void {
                    foreach ($rows as $row) {
                        $rule = (string) $row->rule_id;
                        $stats[$rule] ??= ['done' => 0, 'measured' => 0, 'improved' => 0, 'reopened' => 0];
                        $stats[$rule]['done'] += $row->status === 'done' ? 1 : 0;
                        $stats[$rule]['reopened'] += (int) $row->reopened_count;
                        $improved = self::improved(json_decode((string) $row->outcome, true));
                        if ($improved !== null) {
                            $stats[$rule]['measured']++;
                            $stats[$rule]['improved'] += $improved ? 1 : 0;
                        }
                    }
                });
        }

        return $this->stats[$cacheKey] = $stats;
    }

    /**
     * Priority multiplier for a rule: 1.0 until enough measured outcomes, then 1 ± range by success rate.
     * With a sector, the brand's own sector is used once it has enough outcomes of its own (ADR-066).
     */
    public function weight(string $ruleId, ?string $sector = null): float
    {
        $min = max(1, (int) config('moxdop-advisor.brain.min_measured', 5));
        $range = max(0.0, min(0.5, (float) config('moxdop-advisor.brain.weight_range', 0.2)));
        $row = null;
        if ($sector !== null && $sector !== '') {
            $sectorRow = $this->all($sector)[$ruleId] ?? null;
            $row = $sectorRow !== null && $sectorRow['measured'] >= $min ? $sectorRow : null;
        }
        $row ??= $this->all()[$ruleId] ?? null;
        if ($row === null || $row['measured'] < $min) {
            return 1.0;
        }
        $rate = $row['improved'] / $row['measured'];

        return round(1 - $range + 2 * $range * $rate, 3);
    }

    /**
     * Apply weights to candidate items / tasks (priority_score) before they are written.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function reweigh(array $rows, ?string $sector = null): array
    {
        return array_map(function (array $row) use ($sector): array {
            $weight = $this->weight((string) ($row['rule_id'] ?? ''), $sector);
            if ($weight !== 1.0 && isset($row['priority_score'])) {
                $row['priority_score'] = round((float) $row['priority_score'] * $weight, 2);
            }

            return $row;
        }, $rows);
    }

    /** Did the measured outcome move in the good direction? null when not measured. */
    public static function improved(?array $outcome): ?bool
    {
        $measurement = is_array($outcome['d56'] ?? null) && ($outcome['d56']['status'] ?? null) === 'measured' ? $outcome['d56'] : $outcome;
        if (! is_array($measurement) || ($measurement['status'] ?? null) !== 'measured' || ! is_numeric($measurement['before'] ?? null) || ! is_numeric($measurement['after'] ?? null)) {
            return null;
        }
        $delta = (float) $measurement['after'] - (float) $measurement['before'];

        return ($measurement['good_direction'] ?? 'up') === 'down' ? $delta < 0 : $delta > 0;
    }
}
