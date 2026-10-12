<?php

use App\Services\Ai\ClaudeApiWindow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Sorgu kütüphanesini bitir (yakup, 2026-10-11): while the free Claude API credit lasts (until 25 October) the
 * finish chain is queued once on deploy — catalog clean-up, the unassigned queries asked again on Haiku, clustering,
 * content titles and a check shown on the queries page. Only jobs are queued; the deploy does not wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! ClaudeApiWindow::active() || ! Schema::hasTable('queries') || ! Schema::hasTable('prompt_versions')) {
            return;
        }
        try {
            Artisan::call('moxdop:queries:finish');
        } catch (Throwable) {
            // A fresh database (no admin, no queue tables yet): nothing to finish.
        }
    }

    public function down(): void
    {
        // The chain only moves data forward; nothing to undo.
    }
};
