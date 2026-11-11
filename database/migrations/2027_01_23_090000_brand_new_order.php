<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marka Düzeni 2.5: since when a brand runs in the "yeni düzen" (null = the old row-producing order). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brands') && ! Schema::hasColumn('brands', 'new_order_at')) {
            Schema::table('brands', fn (Blueprint $table) => $table->timestamp('new_order_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('brands') && Schema::hasColumn('brands', 'new_order_at')) {
            Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('new_order_at'));
        }
    }
};
