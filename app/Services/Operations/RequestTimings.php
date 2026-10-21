<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Where the panel spends its time, measured on live traffic (2026-10-21, "sistem hızlı değil"): per page / Livewire
 * component the number of requests, their total and slowest time and their database queries, plus the relations still
 * lazy-loaded one row at a time. Hourly buckets in the cache, 48 hours kept; read through MCP system-health.
 */
final class RequestTimings
{
    private const string PREFIX = 'request-timings:';

    private const int KEEP_SECONDS = 172800;

    public function record(string $key, float $ms, int $queries): void
    {
        $bucket = $this->bucket();
        try {
            $this->remember($bucket, $key);
            Cache::increment($this->metric($bucket, $key, 'n'));
            Cache::increment($this->metric($bucket, $key, 'ms'), (int) round($ms));
            Cache::increment($this->metric($bucket, $key, 'q'), $queries);
            if ($ms >= 1000) {
                Cache::increment($this->metric($bucket, $key, 'slow'));
            }
            $max = $this->metric($bucket, $key, 'max');
            if ((int) Cache::get($max, 0) < $ms) {
                Cache::put($max, (int) round($ms), self::KEEP_SECONDS);
            }
        } catch (Throwable) {
            // Measuring never breaks a page.
        }
    }

    /** @var array<string, int> lazy loads of the current request, written by flushLazyLoads() */
    private static array $lazy = [];

    /** A relation loaded on its own for one row (an N+1 candidate), counted per model.relation in memory. */
    public static function lazyLoad(string $relation): void
    {
        self::$lazy[$relation] = (self::$lazy[$relation] ?? 0) + 1;
    }

    /** Writes this request's lazy loads: one "lazy:" row per relation, its loads counted as queries. */
    public function flushLazyLoads(): void
    {
        foreach (self::$lazy as $relation => $count) {
            $this->record('lazy:'.$relation, 0, $count);
        }
        self::$lazy = [];
    }

    /**
     * The most repeated query shapes of the last request of a page that ran many queries (latest per key).
     *
     * @param  array<string, int>  $shapes
     */
    public function recordHeavy(string $key, int $queries, array $shapes): void
    {
        try {
            $heavy = (array) Cache::get(self::PREFIX.'heavy', []);
            $heavy[$key] = ['at' => now()->toIso8601String(), 'queries' => $queries,
                'top' => array_map(fn (string $sql, int $n): array => ['n' => $n, 'sql' => $sql], array_keys($shapes), $shapes)];
            Cache::put(self::PREFIX.'heavy', array_slice($heavy, -20, null, true), self::KEEP_SECONDS);
        } catch (Throwable) {
        }
    }

    /** @return array<string, array{at: string, queries: int, top: list<array{n: int, sql: string}>}> */
    public function heavy(): array
    {
        return (array) Cache::get(self::PREFIX.'heavy', []);
    }

    /**
     * The last $hours hours, slowest total first.
     *
     * @return list<array{key: string, requests: int, total_s: float, avg_ms: int, max_ms: int, slow: int, queries_per_request: float}>
     */
    public function top(int $hours = 24, int $limit = 25): array
    {
        $totals = [];
        for ($i = 0; $i < $hours; $i++) {
            $bucket = now()->subHours($i)->format('YmdH');
            foreach ((array) Cache::get(self::PREFIX.$bucket.':keys', []) as $key) {
                $row = $totals[$key] ?? ['n' => 0, 'ms' => 0, 'q' => 0, 'slow' => 0, 'max' => 0];
                foreach (['n', 'ms', 'q', 'slow'] as $metric) {
                    $row[$metric] += (int) Cache::get($this->metric($bucket, $key, $metric), 0);
                }
                $row['max'] = max($row['max'], (int) Cache::get($this->metric($bucket, $key, 'max'), 0));
                $totals[$key] = $row;
            }
        }
        $rows = [];
        foreach ($totals as $key => $row) {
            if ($row['n'] === 0) {
                continue;
            }
            $rows[] = [
                'key' => (string) $key, 'requests' => $row['n'], 'total_s' => round($row['ms'] / 1000, 1),
                'avg_ms' => (int) round($row['ms'] / $row['n']), 'max_ms' => $row['max'], 'slow' => $row['slow'],
                'queries_per_request' => round($row['q'] / $row['n'], 1),
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$b['total_s'], $b['requests']] <=> [$a['total_s'], $a['requests']]);

        return array_slice($rows, 0, $limit);
    }

    private function bucket(): string
    {
        return now()->format('YmdH');
    }

    private function metric(string $bucket, string $key, string $metric): string
    {
        return self::PREFIX.$bucket.':'.md5($key).':'.$metric;
    }

    private function remember(string $bucket, string $key): void
    {
        if (! Cache::add(self::PREFIX.$bucket.':seen:'.md5($key), 1, self::KEEP_SECONDS)) {
            return;
        }
        // Several new keys at the same moment can race here; at worst one key of that hour is missing from the list.
        $keys = (array) Cache::get(self::PREFIX.$bucket.':keys', []);
        $keys[] = $key;
        Cache::put(self::PREFIX.$bucket.':keys', array_values(array_unique($keys)), self::KEEP_SECONDS);
        foreach (['n', 'ms', 'q', 'slow'] as $metric) {
            Cache::add($this->metric($bucket, $key, $metric), 0, self::KEEP_SECONDS);
        }
    }
}
