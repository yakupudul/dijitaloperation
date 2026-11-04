<?php

use App\Services\AiTasks\AiWorkers;
use Illuminate\Database\Migrations\Migration;

/**
 * Claude API (2026-11-04): every call was refused with "tool_choice: type tool and any are not supported for this
 * model" (Claude 5.5 models); answers now come through structured outputs. The row's stand-down from that error is
 * cleared on deploy, so the next round calls the API at once instead of waiting out the old error. Runs once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        app(AiWorkers::class)->resume(AiWorkers::CLAUDE_API);
    }

    public function down(): void {}
};
