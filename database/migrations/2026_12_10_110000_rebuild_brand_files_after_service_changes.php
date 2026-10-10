<?php

use App\Jobs\Brand\RefreshBrandFilesJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brand files built before the brand's services were added kept saying "hizmet yok" (Arısoy, 2026-10-10): every brand
 * with services gets its Bilgi dosyası and Marka bilgi kartı built again now (rules only, no AI).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('moxdop.brand_files_live_refresh', true) || ! Schema::hasTable('brand_offerings')) {
            return;
        }
        foreach (DB::table('brand_offerings')->distinct()->pluck('brand_id') as $brandId) {
            RefreshBrandFilesJob::dispatch((int) $brandId);
        }
    }

    public function down(): void {}
};
