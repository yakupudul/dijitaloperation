<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorgu otomatik pilotu: a query goes to AI once for service / filter triage (`ai_checked_at`) and once for
 * clustering (`cluster_checked_at`: placed, or skipped as another service / not relevant); neither is asked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            if (! Schema::hasColumn('queries', 'ai_checked_at')) {
                $table->timestampTz('ai_checked_at')->nullable();
                $table->timestampTz('cluster_checked_at')->nullable();
                $table->index(['service_id', 'cluster_checked_at'], 'queries_cluster_checked_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            $table->dropIndex('queries_cluster_checked_idx');
            $table->dropColumn(['ai_checked_at', 'cluster_checked_at']);
        });
    }
};
