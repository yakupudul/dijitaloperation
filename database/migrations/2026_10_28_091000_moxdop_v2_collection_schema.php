<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 — Faz 1 (Toplama).
 *
 * 1. pages.category is decided in Faz 4 (system + AI + operator): null until then.
 * 2. DataForSEO caches: `serp_results` (top-10 per query × location × language × device, 30 days) and
 *    `query_volumes` (search volume per query × location × language, 90 days).
 * 3. Tables of datasets that left the collection catalogue and that nothing reads any more are dropped:
 *    ga4_landing_channel_daily (replaced by ga4_landing_source_daily) and the paid DataForSEO Labs snapshots
 *    (ranked keywords, keywords for site, competitor domains). Other dropped datasets keep their (now static) tables
 *    because read services still reference them; retention empties them after 16 months.
 */
return new class extends Migration
{
    private const array DROP = [
        'ga4_landing_channel_daily',
        'dataforseo_ranked_keyword_snapshot',
        'dataforseo_keyword_site_snapshot',
        'dataforseo_competitor_domain_snapshot',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('pages', 'category')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->string('category', 16)->nullable()->default(null)->change();
            });
        }

        if (! Schema::hasTable('serp_results')) {
            Schema::create('serp_results', function (Blueprint $table): void {
                $table->id();
                $table->string('query', 500);
                $table->char('query_hash', 64);
                $table->unsignedInteger('location_code');
                $table->string('language_code', 8);
                $table->string('device', 8)->default('desktop'); // desktop | mobile
                $table->json('results'); // list of {rank, url, domain, title, type}
                $table->decimal('cost_usd', 10, 5)->default(0);
                $table->timestampTz('fetched_at');
                $table->timestampsTz();

                $table->unique(['query_hash', 'location_code', 'language_code', 'device'], 'serp_results_key_uq');
                $table->index('fetched_at', 'serp_results_fetched_idx');
            });
        }

        if (! Schema::hasTable('query_volumes')) {
            Schema::create('query_volumes', function (Blueprint $table): void {
                $table->id();
                $table->string('query', 500);
                $table->char('query_hash', 64);
                $table->unsignedInteger('location_code');
                $table->string('language_code', 8);
                $table->unsignedBigInteger('volume')->nullable(); // null = provider has no volume for it
                $table->json('monthly')->nullable(); // [{year, month, volume}]
                $table->decimal('competition', 8, 4)->nullable();
                $table->decimal('cpc', 12, 4)->nullable();
                $table->timestampTz('fetched_at');
                $table->timestampsTz();

                $table->unique(['query_hash', 'location_code', 'language_code'], 'query_volumes_key_uq');
                $table->index('fetched_at', 'query_volumes_fetched_idx');
            });
        }

        foreach (self::DROP as $table) {
            if (DB::getDriverName() === 'pgsql') {
                // A compact table is a view over its *_f_* fact table; drop whichever exists, then the fact table.
                $kind = DB::selectOne('SELECT c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?', [$table])?->relkind;
                if ($kind === 'v') {
                    DB::statement('drop view if exists "'.$table.'" cascade');
                } elseif ($kind !== null) {
                    DB::statement('drop table if exists "'.$table.'" cascade');
                }
                if ($table === 'ga4_landing_channel_daily') {
                    DB::statement('drop table if exists "ga4_f_landing_channel" cascade');
                }

                continue;
            }
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('query_volumes');
        Schema::dropIfExists('serp_results');
        // Dropped fact tables are not recreated (their datasets are no longer collected).
    }
};
