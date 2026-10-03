<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hizmet önceliği tek alan: ★ = `priority = main`. `is_priority` stays as a mirror (older readers use it) and the model
 * keeps the two in sync from now on. Non-destructive: a service starred in either field becomes main in both; nothing
 * is lowered and no column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_offerings') || ! Schema::hasColumn('brand_offerings', 'priority') || ! Schema::hasColumn('brand_offerings', 'is_priority')) {
            return;
        }
        DB::table('brand_offerings')->where('is_priority', true)->where('priority', '!=', 'main')->update(['priority' => 'main']);
        DB::table('brand_offerings')->where('priority', 'main')->where('is_priority', false)->update(['is_priority' => true]);
    }

    public function down(): void
    {
        // Data backfill only; nothing to undo.
    }
};
