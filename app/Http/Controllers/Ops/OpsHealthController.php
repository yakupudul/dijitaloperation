<?php

namespace App\Http\Controllers\Ops;

use App\Services\Observability\OperationalHealthSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        try {
            DB::select('select 1');
            $db = 'HEALTHY';
        } catch (Throwable) {
            $db = 'UNHEALTHY';
        }

        $ready = $db === 'HEALTHY';

        return response()->json([
            'status' => $ready ? 'HEALTHY' : 'UNHEALTHY',
            'check' => 'readiness',
            'dependencies' => [
                'database' => $db,
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
}
