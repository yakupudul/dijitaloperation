<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional GA4 property-daily metrics collected only when the property/API
     * supports them. Null means not collected/unavailable; measured zero remains 0.
     * Added one column per ALTER so SQLite and PostgreSQL both apply safely.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ga4_property_daily')) {
            return;
        }

        $this->addColumnIfMissing('newUsers', function (Blueprint $table): void {
            $table->bigInteger('newUsers')->nullable();
        });
        $this->addColumnIfMissing('conversions', function (Blueprint $table): void {
            $table->decimal('conversions', 20, 6)->nullable();
        });
        $this->addColumnIfMissing('keyEvents', function (Blueprint $table): void {
            $table->decimal('keyEvents', 20, 6)->nullable();
        });
        $this->addColumnIfMissing('totalRevenue', function (Blueprint $table): void {
            $table->decimal('totalRevenue', 20, 6)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ga4_property_daily')) {
            return;
        }

        foreach (['newUsers', 'conversions', 'keyEvents', 'totalRevenue'] as $column) {
            if (Schema::hasColumn('ga4_property_daily', $column)) {
                Schema::table('ga4_property_daily', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function addColumnIfMissing(string $column, callable $define): void
    {
        if (Schema::hasColumn('ga4_property_daily', $column)) {
            return;
        }

        Schema::table('ga4_property_daily', $define);
    }
};
