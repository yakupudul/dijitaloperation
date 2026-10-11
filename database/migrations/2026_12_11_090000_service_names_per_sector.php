<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hizmet adı sektör içinde tekil (yakup, 2026-10-11): "Botoks" may be a service of two sectors. A catalog name was
 * unique across the whole catalog, so resolveOrCreate handed a service of ANOTHER sector back. `sector_scope` is ''
 * for every existing name (they stay unique as they were); a same-name service created in another sector carries
 * that sector's code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_catalog_names', 'sector_scope')) {
            return;
        }
        Schema::table('service_catalog_names', function (Blueprint $table): void {
            $table->string('sector_scope', 120)->default('');
        });
        Schema::table('service_catalog_names', function (Blueprint $table): void {
            $table->dropUnique('service_catalog_name_normalized_uq');
            $table->unique(['normalized_key', 'sector_scope'], 'service_catalog_name_scope_uq');
            $table->index('normalized_key', 'service_catalog_name_key_idx');
        });
    }

    public function down(): void
    {
        // Names of different sectors may now share a key; the old catalog-wide unique index cannot come back safely.
    }
};
