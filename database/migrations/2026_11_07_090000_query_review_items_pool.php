<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sorgular › Silinecekler: query_review_items becomes one permanent pool — at most one open proposal per query (the
 * latest rescan wins) and `kept_at` for "Tut" (the same proposal is not offered again).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('query_review_items')->whereNotIn('id', DB::table('query_review_items')->selectRaw('max(id)')->groupBy('query_id'))->delete();
        Schema::table('query_review_items', function (Blueprint $table): void {
            $table->timestampTz('kept_at')->nullable();
            $table->unique('query_id', 'query_review_items_query_uq');
            $table->index(['kind', 'kept_at'], 'query_review_items_kind_kept_idx');
        });
    }

    public function down(): void
    {
        Schema::table('query_review_items', function (Blueprint $table): void {
            $table->dropIndex('query_review_items_kind_kept_idx');
            $table->dropUnique('query_review_items_query_uq');
            $table->dropColumn('kept_at');
        });
    }
};
