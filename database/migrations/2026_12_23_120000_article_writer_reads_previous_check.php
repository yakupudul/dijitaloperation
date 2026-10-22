<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik motoru (Aşama 8, 2026-10-22): an article the final check holds back is written again with the check's reason
 * (`plan.previous_check`); the writer adopts site-write-article-v14, which reads it. The model is kept.
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
