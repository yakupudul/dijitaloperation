<?php

namespace App\Support\Performance;

use Illuminate\Support\Facades\DB;

/**
 * Measures SQL query count and wall duration for a callable (Prompt 65).
 */
final class QueryCountProbe
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{result: T, queries: int, duration_ms: float, peak_memory_bytes: int}
     */
    public function measure(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $memoryBefore = memory_get_usage(true);

        try {
            $result = $callback();
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $durationMs = (hrtime(true) - $started) / 1_000_000;
        $peak = max(memory_get_usage(true) - $memoryBefore, 0);

        return [
            'result' => $result,
            'queries' => $queries,
            'duration_ms' => round($durationMs, 3),
            'peak_memory_bytes' => $peak,
        ];
    }
}
