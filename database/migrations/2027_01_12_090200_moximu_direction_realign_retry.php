<?php

use App\Models\Brand;
use App\Models\BrandDirectionChange;
use App\Services\Brand\BrandDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Marka rotası, Moximu retry (2026-11-09): the first realignment was queued during the deploy, while the old release's
 * workers still ran, and was lost. When Moximu has no major change yet one is written; either way the scheduler
 * (moxdop:brands:direction --pending, every ten minutes) queues the unfinished one with the new release. Skipped in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $brand = Brand::query()->where('name', 'Moximu')->first();
        if ($brand === null || BrandDirectionChange::query()->where('brand_id', $brand->id)->where('magnitude', BrandDirectionChange::MAJOR)->exists()) {
            return;
        }
        try {
            app(BrandDirection::class)->detect($brand, force: true, trigger: 'system',
                reason: 'moximu.com yalnız kliniklerle çalışan klinik büyüme ajansı olarak yeniden kuruldu; eski e-ticaret / web tasarım konuları kenara alınır.');
        } catch (Throwable $e) {
            Log::warning('Moximu direction realign retry failed.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void {}
};
