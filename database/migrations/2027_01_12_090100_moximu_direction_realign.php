<?php

use App\Models\Brand;
use App\Services\Brand\BrandDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Marka rotası, first use (yakup 2026-11-09): moximu.com was rebuilt as a clinic growth agency, but its clusters,
 * content ideas (approved and drafted e-commerce / web design titles) and profile post drafts still followed the old
 * agency. The direction is marked as changed once: ApplyBrandDirectionJob reads them against the current direction.
 * Every other brand gets its baseline from the nightly check. Skipped in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $brand = Brand::query()->where('name', 'Moximu')->first();
        if ($brand === null) {
            return;
        }
        try {
            app(BrandDirection::class)->detect($brand, force: true, trigger: 'system',
                reason: 'moximu.com yalnız kliniklerle çalışan klinik büyüme ajansı olarak yeniden kuruldu; eski e-ticaret / web tasarım konuları kenara alınır.');
        } catch (Throwable $e) {
            Log::warning('Moximu direction realign failed.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void {}
};
