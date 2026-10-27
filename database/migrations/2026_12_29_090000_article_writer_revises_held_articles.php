<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Otomatik düzeltme (yakup 2026-10-27): an article the final check or a sector rule holds back is corrected from its own
 * text (`revise_from`) instead of being written from scratch; the writer adopts site-write-article-v15, which reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.write_article']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
    }

    public function down(): void
    {
        // Earlier prompt versions stay in the history ("Bu sürüme dön").
    }
};
