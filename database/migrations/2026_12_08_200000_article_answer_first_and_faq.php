<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Cevap öncelikli içerik (yakup, 2026-10-07): the article writer (delegated to Claude, so it does not follow a changed
 * code default by itself) adopts site-write-article-v10: a direct answer at the top and the questions as <h3>…?</h3>
 * under "Sık sorulan sorular", which the WordPress draft turns into FAQPage schema. The model is kept.
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
