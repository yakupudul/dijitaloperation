<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forward-safe correction for environments that may already have applied the
     * original optional GA4 metric migration. Missing/uncollected optional metrics
     * must remain null, and conversion/key-event values must preserve fractions.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ga4_property_daily')) {
            return;
        }

        if (Schema::hasColumn('ga4_property_daily', 'newUsers')) {
            Schema::table('ga4_property_daily', function (Blueprint $table): void {
                $table->bigInteger('newUsers')->nullable()->default(null)->change();
            });
        }

        if (Schema::hasColumn('ga4_property_daily', 'conversions')) {
            Schema::table('ga4_property_daily', function (Blueprint $table): void {
                $table->decimal('conversions', 20, 6)->nullable()->default(null)->change();
            });
        }

        if (Schema::hasColumn('ga4_property_daily', 'keyEvents')) {
            Schema::table('ga4_property_daily', function (Blueprint $table): void {
                $table->decimal('keyEvents', 20, 6)->nullable()->default(null)->change();
            });
        }

        if (Schema::hasColumn('ga4_property_daily', 'totalRevenue')) {
            Schema::table('ga4_property_daily', function (Blueprint $table): void {
                $table->decimal('totalRevenue', 20, 6)->nullable()->default(null)->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally no-op: reverting decimal optional metrics to integer/default
        // zero would destroy fractional values and collapse unavailable into measured 0.
    }
};
