<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik fikir havuzu (yakup, 2026-10-09 "sistem çok iyi şekilde üretsin"): the pool's topics are now chosen by rules
 * from the brand's data and the AI writes one idea per candidate (site-weekly-content-v6, `candidate_id`). The
 * operation adopts the new code default; the model in use is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.weekly_content']]);
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
