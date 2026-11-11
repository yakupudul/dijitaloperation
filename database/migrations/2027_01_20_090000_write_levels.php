<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bağlantı Düzeni 3.2 (yetki kademeleri): a brand keeps its level per channel (null = the default of WriteLevels), and
 * every write remembers whether the operator started it by hand or the system did by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brands') && ! Schema::hasColumn('brands', 'write_levels')) {
            Schema::table('brands', fn (Blueprint $table) => $table->json('write_levels')->nullable());
        }
        if (Schema::hasTable('external_write_actions') && ! Schema::hasColumn('external_write_actions', 'origin')) {
            Schema::table('external_write_actions', fn (Blueprint $table) => $table->string('origin', 8)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('brands') && Schema::hasColumn('brands', 'write_levels')) {
            Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('write_levels'));
        }
        if (Schema::hasTable('external_write_actions') && Schema::hasColumn('external_write_actions', 'origin')) {
            Schema::table('external_write_actions', fn (Blueprint $table) => $table->dropColumn('origin'));
        }
    }
};
