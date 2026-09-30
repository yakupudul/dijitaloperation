<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 — Faz 3 düzeltmeleri:
 * 1. clusters: user_need (the need one page answers), exclusions (topics NOT to include), version (+1 per saved edit).
 * 2. brand_cluster_pages (brand-only targeting, never the shared cluster): target query override, excluded for brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clusters', 'user_need')) {
            Schema::table('clusters', function (Blueprint $table): void {
                $table->text('user_need')->nullable()->after('intent');
                $table->json('exclusions')->nullable()->after('subtopics');
                $table->unsignedInteger('version')->default(1)->after('locked');
            });
        }
        if (! Schema::hasColumn('brand_cluster_pages', 'excluded')) {
            Schema::table('brand_cluster_pages', function (Blueprint $table): void {
                $table->text('target_query_override')->nullable()->after('target_query');
                $table->boolean('excluded')->default(false)->after('locked');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('brand_cluster_pages', 'excluded')) {
            Schema::table('brand_cluster_pages', fn (Blueprint $table) => $table->dropColumn(['target_query_override', 'excluded']));
        }
        if (Schema::hasColumn('clusters', 'user_need')) {
            Schema::table('clusters', fn (Blueprint $table) => $table->dropColumn(['user_need', 'exclusions', 'version']));
        }
    }
};
