<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Bozuk çeviri onarımı (2026-10-19): the new operation's prompt is stored and the first scan runs at once, so OPC
 * Klinik's 23 Italian pages written in game slang get their faithful translation on the Onarım masası this week.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('suggestions') || ! Schema::hasTable('pages')) {
            return;
        }
        try {
            Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.translation_fix']]);
        } catch (Throwable) {
            // No admin yet: the code default is used from the first call anyway.
        }
        try {
            Artisan::call('moxdop:site:broken-translations');
        } catch (Throwable) {
            // A fresh database: nothing to scan.
        }
    }

    public function down(): void
    {
        // Prepared rows stay on the desk.
    }
};
