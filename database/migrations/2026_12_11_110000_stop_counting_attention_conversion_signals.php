<?php

use App\Jobs\Brand\RefreshBrandFilesJob;
use App\Services\Measurement\BrandConversionDictionary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Attention signals (scroll, form start, page view, "Local actions - Website visits / Other engagements") found before
 * they were left out kept counting as customers (2026-10-11): automatic rows stop counting; rows the operator counted
 * are not switched silently but marked, so Dönüşümler and the brand card warn "Etkileşim sinyali müşteri sayılıyor".
 * The brand files of the touched brands are built again (same job as the card's "Yenile").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_conversion_sources')) {
            return;
        }
        try {
            $result = app(BrandConversionDictionary::class)->cleanEngagement();
        } catch (Throwable $exception) {
            report($exception); // a data clean-up never blocks a deploy; the nightly dictionary run repeats it

            return;
        }
        if (! config('moxdop.brand_files_live_refresh', true)) {
            return;
        }
        foreach ($result['brands'] as $brandId) {
            try {
                RefreshBrandFilesJob::dispatch($brandId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function down(): void {}
};
