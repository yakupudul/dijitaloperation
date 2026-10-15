<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Küme haritası ve yeni yazım kuralı (yakup, 2026-10-15 "sırayla hepsini yap"): the article writer and the otopilot
 * check (delegated to Claude, so they do not follow a changed code default by themselves) adopt site-write-article-v11
 * (its own question, length by subject, Google's first results as the bar, the brand's own facts, the cluster map) and
 * content-review-v2. Then the dated articles not sent yet are planned again by the cluster map and written again; only
 * jobs are queued, the deploy does not wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.write_article', 'content.review']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
        if (app()->runningUnitTests() || ! Schema::hasTable('suggestions')) {
            return;
        }
        try {
            Artisan::call('moxdop:content:rework');
        } catch (Throwable) {
            // A fresh database: nothing to write again.
        }
    }

    public function down(): void
    {
        // Earlier prompt versions stay in the history ("Bu sürüme dön"); rewritten articles stay rewritten.
    }
};
