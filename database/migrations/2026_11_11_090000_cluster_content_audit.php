<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Küme ↔ içerik denetimi (`ClusterAudit`): per brand cluster row the coverage the AI read from the page content
 * (full / partial / none), the missing items (`gaps`: what the cluster's queries, facets, AI questions and — when the
 * cluster needs it — service areas ask for and the page does not answer) and when it was checked; per cluster the
 * questions people ask AI assistants (`ai_queries`, "{bölge}" where a place fits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_cluster_pages', function (Blueprint $table): void {
            $table->string('coverage', 10)->nullable();
            $table->json('gaps')->nullable();
            $table->timestampTz('audited_at')->nullable();
        });
        Schema::table('clusters', function (Blueprint $table): void {
            $table->json('ai_queries')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_cluster_pages', function (Blueprint $table): void {
            $table->dropColumn(['coverage', 'gaps', 'audited_at']);
        });
        Schema::table('clusters', function (Blueprint $table): void {
            $table->dropColumn('ai_queries');
        });
    }
};
