<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Özet (yakup 2026-11-02): pages.excerpt holds a WordPress post's own Excerpt field as the connector (≥ 1.36.0) reports
 * it: '' when none was written, null while the site's connector does not report it yet (older plugin). The Onarım
 * masası audit reads it and the title / description batch writes it, so the batch prompt adopts its v4 default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pages') && ! Schema::hasColumn('pages', 'excerpt')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->text('excerpt')->nullable();
            });
        }
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.seo_fields_batch']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pages', 'excerpt')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->dropColumn('excerpt');
            });
        }
    }
};
