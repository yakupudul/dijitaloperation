<?php

namespace App\Http\Controllers\Ops;

use App\Services\Observability\OperationalHealthSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Cheap liveness / readiness — no tenant data, no credentials, no provider polling.
 */
final class OpsHealthController
{
    public function liveness(): JsonResponse
    {
        return response()->json([
            'status' => 'HEALTHY',
            'check' => 'liveness',
        ]);
    }

    public function readiness(): JsonResponse
    {
        $database = $this->databaseStatus();
        $storage = $this->storageStatus();
        $redis = $this->redisStatus();

        $blocking = [$database, $storage];
        if ($redis !== 'SKIPPED') {
            $blocking[] = $redis;
        }

        $ready = ! in_array('UNHEALTHY', $blocking, true);

        return response()->json([
            'status' => $ready ? 'HEALTHY' : 'UNHEALTHY',
            'check' => 'readiness',
            'dependencies' => [
                'database' => $database,
                'redis' => $redis,
                'storage' => $storage,
            ],
        ], $ready ? 200 : 503);
    }

    /**
     * Internal diagnostic snapshot — auth required by route middleware.
     */
    public function snapshot(OperationalHealthSnapshot $snapshot): JsonResponse
    {
        return response()->json($snapshot->snapshot());
    }

    private function databaseStatus(): string
    {
        try {
            DB::select('select 1');

            return 'HEALTHY';
        } catch (Throwable) {
            return 'UNHEALTHY';
        }
    }

    private function storageStatus(): string
    {
        $path = storage_path('app');
        if (! is_dir($path) || ! is_writable($path)) {
            return 'UNHEALTHY';
        }

        return 'HEALTHY';
    }

    /**
     * Ping Redis only when cache or the default queue actually uses it.
     * Collection-on-Redis is still required for Horizon staging, but must not
     * fail cheap public readiness in SQLite/sync test runs.
     */
    private function redisStatus(): string
    {
        $queue = (string) config('queue.default');
        $cache = (string) config('cache.default');

        if (! in_array($queue, ['redis'], true) && ! in_array($cache, ['redis'], true)) {
            return 'SKIPPED';
        }

        try {
            Redis::connection()->ping();

            return 'HEALTHY';
        } catch (Throwable) {
            return 'UNHEALTHY';
        }
    }
}
