<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlink Faz 0 (yakup 2026-10-26, "3 Kasım'da yap"):
 * 1. backlinks: the Bing Webmaster link list (source "bing") brings the link text and is read again every week, so a row
 *    knows when it was last seen and when it disappeared (status kaldirildi).
 * 2. bing_sites: when the site's links were last read and the last error.
 * 3. serp_results.source: a top-10 the operator pasted from Google (manual) next to the DataForSEO ones; the competitor
 *    refresh reads a pasted one like a cached one, so Rakipler and Anılma fırsatları work without a paid call.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backlinks') && ! Schema::hasColumn('backlinks', 'anchor')) {
            Schema::table('backlinks', function (Blueprint $table): void {
                $table->string('anchor', 500)->nullable();
                $table->date('last_seen')->nullable();
                $table->date('lost_at')->nullable();
            });
        }
        if (Schema::hasTable('bing_sites') && ! Schema::hasColumn('bing_sites', 'links_collected_at')) {
            Schema::table('bing_sites', function (Blueprint $table): void {
                $table->timestampTz('links_collected_at')->nullable();
                $table->string('links_error', 500)->nullable();
            });
        }
        if (Schema::hasTable('serp_results') && ! Schema::hasColumn('serp_results', 'source')) {
            Schema::table('serp_results', function (Blueprint $table): void {
                $table->string('source', 16)->default('dataforseo'); // dataforseo | manual
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('backlinks', 'anchor')) {
            Schema::table('backlinks', function (Blueprint $table): void {
                $table->dropColumn(['anchor', 'last_seen', 'lost_at']);
            });
        }
        if (Schema::hasColumn('bing_sites', 'links_collected_at')) {
            Schema::table('bing_sites', function (Blueprint $table): void {
                $table->dropColumn(['links_collected_at', 'links_error']);
            });
        }
        if (Schema::hasColumn('serp_results', 'source')) {
            Schema::table('serp_results', function (Blueprint $table): void {
                $table->dropColumn('source');
            });
        }
    }
};
