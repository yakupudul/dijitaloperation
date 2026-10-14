<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * yakup, 2026-10-13: which dental Business Profiles lack categories and services. Once on deploy, read-only: the live
 * counts per profile (moxdop:gbp:coverage, like Avrupadent's sector) as a system Claude note, after the Çiğli copy ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('claude_notes')) {
            return;
        }
        try {
            Artisan::queue('moxdop:gbp:coverage', ['--like-brand' => '7'])->delay(now()->addMinutes(35));
        } catch (Throwable) {
            // A fresh database: nothing to read.
        }
    }

    public function down(): void
    {
        // A note only.
    }
};
