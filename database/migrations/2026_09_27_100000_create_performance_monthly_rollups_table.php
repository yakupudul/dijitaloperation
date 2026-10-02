<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly aggregate of daily performance rows older than the daily retention window (25 months by default).
 * One row per source table, month and dimension set; metrics are sums (ratios are weighted averages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_monthly_rollups', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table', 80);
            $table->unsignedBigInteger('digital_asset_id')->nullable()->index();
            $table->date('month');
            $table->char('dimension_hash', 64);
            $table->json('dimensions');
            $table->json('metrics');
            $table->unsignedSmallInteger('day_count')->default(0);
            $table->unsignedInteger('rows_rolled')->default(0);
            $table->timestampsTz();

            $table->unique(['source_table', 'month', 'dimension_hash'], 'performance_monthly_rollups_uq');
            $table->index(['source_table', 'digital_asset_id', 'month'], 'performance_monthly_rollups_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_monthly_rollups');
    }
};
