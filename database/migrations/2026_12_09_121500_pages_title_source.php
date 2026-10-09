<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pages.title_source: where a WordPress page's stored title came from — `seo` (the SEO plugin's own title field) or
 * `post` (the post title, because the plugin renders a template such as "%title% | Site name"). A post title is not
 * what Google shows, so the title length checks skip it. Null for pages not synced from WordPress yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pages') && ! Schema::hasColumn('pages', 'title_source')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->string('title_source', 8)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pages', 'title_source')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->dropColumn('title_source');
            });
        }
    }
};
