<?php

use App\Services\Site\ContentPlanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Rule fixes (yakup, 2026-10-09 "hatalı kurgulanmış neler varsa düzelt"): open update ideas beyond the first for the
 * same page are closed (one waiting update per page), and the operations whose prompts the fixes changed (Business
 * Profile description: own area only; SEO title batch: keep brand and area words; image alts: no decorative images)
 * adopt their new code prompts, keeping their model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suggestions')) {
            ContentPlanner::retireDuplicateUpdates();
        }
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['gbp.description', 'site.seo_fields_batch', 'site.image_alts']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
    }

    public function down(): void
    {
        // Closed ideas stay closed; earlier prompt versions stay in the history ("Bu sürüme dön").
    }
};
