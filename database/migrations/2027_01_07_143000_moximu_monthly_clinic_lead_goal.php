<?php

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * yakup 2026-11-07 ("sen tahmin et"): Moximu's own monthly goal for the clinic-only period replaces the old proposal
 * (55, read from the old all-business Meta WhatsApp chats). 25 clinic applications a month: Meta lead form 12 (≈9.000 ₺
 * a month at ≈700 ₺ a clinic form in the own-ads plan), web site 8 (form, WhatsApp, call; today ≈10 mixed, clinic-only
 * fewer at first, the new branş pages add), Google Ads 3 (≈4.500 ₺ search), İşletme profili 2. Approved in his name;
 * nightly refresh never overwrites an approved goal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        try {
            $brand = Brand::query()->where('name', 'Moximu')->first();
            if ($brand === null) {
                return;
            }
            $target = BrandTarget::query()->firstOrNew(['brand_id' => $brand->id]);
            $target->forceFill([
                'monthly_leads' => 25,
                'split' => ['web' => 8, 'google_ads' => 3, 'meta' => 12, 'gbp' => 2],
                'status' => BrandTarget::APPROVED,
                'approved_at' => now(),
                'approved_by' => User::query()->orderBy('id')->value('id'),
                'reason' => 'Klinik dönemi tahmini (yakup "sen tahmin et", 7 Kasım 2026): Meta formu 12, web sitesi 8, Google Ads 3, İşletme profili 2.',
            ])->save();
        } catch (Throwable $e) {
            Log::warning('Moximu goal failed.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void {}
};
