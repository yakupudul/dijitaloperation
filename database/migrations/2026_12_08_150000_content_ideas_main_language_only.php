<?php

use App\Services\Site\ContentPlanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * yakup (2026-10-07): content ideas only in the site's main language; other languages get the written article's
 * translation. Open other-language ideas are closed, and the article writer (delegated to Claude, so it does not
 * follow a changed code default by itself) and the idea planner adopt their new code prompts, keeping their model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suggestions')) {
            ContentPlanner::retireOtherLanguageIdeas();
        }
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.write_article', 'site.weekly_content']]);
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
