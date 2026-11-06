<?php

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\BrandIntelligence\BrandOfferingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Follow-up to the Moximu brand refresh: Google Ads Danışmanlığı kept an old ★ set on the services screen (no rank),
 * which setPriorityOrder leaves alone, so it stayed a main service. Every other ★ is cleared here; Klinik SEO and
 * Google İşletme Profili stay the only main services.
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
            $ids = [];
            foreach (['Klinik SEO', 'Google İşletme Profili Yönetimi'] as $label) {
                $offering = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->get()
                    ->first(fn (BrandOffering $o): bool => $o->displayName() === $label);
                if ($offering !== null) {
                    $ids[] = (int) $offering->id;
                }
            }
            if (count($ids) === 2) {
                app(BrandOfferingService::class)->setPriorityOrder($brand, $ids, recordActivity: false);
                BrandOffering::query()->where('brand_id', $brand->id)->whereNotIn('id', $ids)->get()
                    ->each(fn (BrandOffering $o) => $o->forceFill(['priority' => 'secondary', 'is_priority' => false, 'priority_rank' => null])->save());
                app(BrandIntelligenceContextWriteService::class)->projectIdentityFields($brand);
            }
        } catch (Throwable $e) {
            Log::warning('Moximu main services fix failed.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void {}
};
