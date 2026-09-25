<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a customer / brand from the portfolio archives it (soft delete) instead of removing rows: collected data
 * stays, only collection stops (the asset bindings are disabled) and resumes when the resource is bound again.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'brands', 'digital_assets'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['customers', 'brands', 'digital_assets'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropSoftDeletes();
                });
            }
        }
    }
};
