<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik kontrolü (2026-10-16): the writer adopts site-write-article-v12 (no phone / address, no notes about its own
 * data, the next step inside the last section) and the otopilot check content-review-v3 (length is the system's
 * check, not the reader's). Then the 14 Oct articles still waiting in the pool, not only the dated ones, are written
 * again by the new rules, and new-rule articles held by the old check or left with an empty heading are checked again.
 * Only jobs are queued; the deploy does not wait.
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
