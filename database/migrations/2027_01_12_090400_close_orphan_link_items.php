<?php

use App\Services\Website\Pages\PageStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Marka rotası (yakup 2026-11-09): after moximu.com was rebuilt, "İç link ekle" items of its old pages (/platformlar/…)
 * stayed on the work list. Closed once now; the nightly audit and every direction change close them from here on.
 * Skipped in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        try {
            PageStore::closeOrphanFixes();
        } catch (Throwable $e) {
            Log::warning('Closing orphan work items failed.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void {}
};
