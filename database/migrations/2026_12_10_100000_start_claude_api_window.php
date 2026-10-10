<?php

use App\Services\Ai\ClaudeApiWindow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Claude API dönemi (yakup, 2026-10-10): 100 USD free Claude credit until 25 October, so until then every AI operation
 * that may move runs on the Claude API and the work waiting in the subscription queue starts again there. The
 * operations go back to their earlier models on 25 October (scheduled moxdop:ai:claude-api-window end).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! ClaudeApiWindow::active() || ! Schema::hasTable('prompt_versions') || ! Schema::hasTable('users')) {
            return;
        }
        try {
            Artisan::call('moxdop:ai:claude-api-window', ['action' => 'start']);
        } catch (Throwable) {
            // No admin / roles yet (a fresh database): nothing to move.
        }
    }

    public function down(): void
    {
        // Ended by `moxdop:ai:claude-api-window end` (scheduled for 25 October).
    }
};
