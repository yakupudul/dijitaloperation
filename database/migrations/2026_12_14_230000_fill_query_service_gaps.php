<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Eksik hizmetler (yakup, 2026-10-14 "Ekle, yeniden ata"): on deploy, every sector with queries AI found no service for
 * gets its missing services added and those queries triaged again. Only jobs are queued; the deploy does not wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('queries')) {
            return;
        }
        try {
            Artisan::call('moxdop:queries:fill-gaps');
        } catch (Throwable) {
            // A fresh database: nothing to fill.
        }
    }

    public function down(): void
    {
        // Services stay; archiving them sends their queries back to triage.
    }
};
