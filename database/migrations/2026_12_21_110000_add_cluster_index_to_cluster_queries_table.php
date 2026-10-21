<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cluster boards, content ideas, the brand dossier and the query pipeline read a cluster's queries by cluster_id; the
 * table only had the unique index on query_id (Postgres does not index foreign keys), so each read walked the whole
 * table (386k sequential scans against 520 index scans, 2026-10-21).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cluster_queries') || Schema::hasIndex('cluster_queries', 'cluster_queries_cluster_idx')) {
            return;
        }

        Schema::table('cluster_queries', function (Blueprint $table): void {
            $table->index(['cluster_id', 'is_suggested'], 'cluster_queries_cluster_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cluster_queries') || ! Schema::hasIndex('cluster_queries', 'cluster_queries_cluster_idx')) {
            return;
        }

        Schema::table('cluster_queries', function (Blueprint $table): void {
            $table->dropIndex('cluster_queries_cluster_idx');
        });
    }
};
