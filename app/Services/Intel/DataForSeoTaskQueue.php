<?php

namespace App\Services\Intel;

use Illuminate\Support\Facades\DB;

/**
 * Ledger of paid DataForSEO live calls (SERP top-10, search volume): each call is a row in `dataforseo_tasks` with
 * its cost, so the monthly cap (DataForSeoSpendGuard) and the Maliyetler screen read one table. v2 posts no queued
 * tasks (map grid, reviews and prospect maps are retired), so nothing is polled.
 */
final class DataForSeoTaskQueue
{
    /** Record a paid live call so it counts toward the cap. */
    public function recordLive(string $purpose, ?int $brandId, string $endpoint, float $cost, ?string $subjectType = null, ?int $subjectId = null, ?string $error = null): void
    {
        DB::table('dataforseo_tasks')->insert([
            'brand_id' => $brandId, 'purpose' => $purpose, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'get_endpoint' => $endpoint, 'status' => $error === null ? 'completed' : 'failed', 'cost_usd' => round($cost, 5),
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null, 'posted_at' => now(), 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
