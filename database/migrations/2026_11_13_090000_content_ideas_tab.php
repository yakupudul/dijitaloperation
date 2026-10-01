<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik fikirleri sekmesi (docs/product/CONTENT_IDEAS_BLUEPRINT.md §5, §8): the "SEO analizi" recipe and the time of
 * the last "Yeniden keşfet" on both the main idea (brand cluster row) and the extra idea rows; the pages that take the
 * cluster's Search Console impressions on the nightly score (wrong page / conflict reasons, first match candidate).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['brand_cluster_pages', 'brand_content_ideas'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                if (! Schema::hasColumn($table, 'recipe')) {
                    $t->json('recipe')->nullable();
                    $t->timestampTz('recipe_at')->nullable();
                    $t->timestampTz('rediscovered_at')->nullable();
                }
            });
        }
        Schema::table('cluster_page_scores', function (Blueprint $t): void {
            if (! Schema::hasColumn('cluster_page_scores', 'page_shares')) {
                $t->json('page_shares')->nullable(); // [{url, impressions, share}] top pages of the cluster's queries
            }
        });
    }

    public function down(): void
    {
        foreach (['brand_cluster_pages', 'brand_content_ideas'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn(['recipe', 'recipe_at', 'rediscovered_at']);
            });
        }
        Schema::table('cluster_page_scores', function (Blueprint $t): void {
            $t->dropColumn('page_shares');
        });
    }
};
