<?php

use App\Services\Ai\AiCredits;
use App\Services\AiTasks\AiWorkers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Claude API (yakup, 2026-11-04): first in the AI order but it spent nothing, while the panel's credit counter still
 * knew only the first 100$ and said "kredi bitti". The credit the Console shows now (225,60$ promotional, Max plan) is
 * recorded, the row's last error is kept for system-health and cleared, so the next round calls the API again and a
 * failure comes back with the provider's own words. Runs once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('ai_credit_topups')) {
            return;
        }
        if (! DB::table('ai_credit_topups')->where('provider', 'anthropic')->where('note', 'like', 'Max 20x%')->exists()) {
            app(AiCredits::class)->topUp('anthropic', 225.60, 'Max 20x promosyon kredisi (Console, 4 Kasım 2026)');
        }
        $workers = app(AiWorkers::class);
        Cache::put('ai-worker-error-previous:'.AiWorkers::CLAUDE_API, [
            'error' => $workers->lastError(AiWorkers::CLAUDE_API),
            'paused_until' => $workers->settings()['paused'][AiWorkers::CLAUDE_API] ?? null,
            'reason' => $workers->downReason(AiWorkers::CLAUDE_API),
            'at' => now()->toIso8601String(),
        ], now()->addDays(14));
        $workers->resume(AiWorkers::CLAUDE_API);
    }

    public function down(): void
    {
        DB::table('ai_credit_topups')->where('provider', 'anthropic')->where('note', 'like', 'Max 20x%')->delete();
    }
};
