<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik işleri hiç beklemez (yakup, 2026-10-12): on deploy, the titles, articles and translations left waiting behind
 * other jobs or in the Claude queue start again on the new content queue. Only jobs are queued; the deploy does not wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('suggestions') || ! Schema::hasTable('ai_tasks')) {
            return;
        }
        try {
            Artisan::call('moxdop:content:rescue');
        } catch (Throwable) {
            // A fresh database: nothing waits.
        }
    }

    public function down(): void
    {
        // Only jobs were queued; nothing to undo.
    }
};
