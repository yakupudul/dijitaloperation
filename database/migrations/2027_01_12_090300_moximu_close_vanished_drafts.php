<?php

use App\Models\Brand;
use App\Models\Suggestion;
use Illuminate\Database\Migrations\Migration;

/**
 * Moximu (yakup 2026-11-09: "sitede taslaklar yok, sen niye var olarak görüyorsun"): 31 approved old-direction content
 * ideas (e-ticaret, Ticimax, CRM, hosting…) were sent as WordPress drafts to the OLD moximu.com install. The site was
 * rebuilt as a fresh WordPress, so those drafts no longer exist; only MoxDOP's own record still said "taslak var".
 * They are closed here (dismissed, note kept), nothing is sent to the site. Skipped in tests.
 */
return new class extends Migration
{
    private const array IDS = [60630, 59641, 59639, 59638, 58413, 58411, 49616, 49533, 49528, 49527, 48896, 48893, 37267, 37266, 37265, 37264, 37263, 37262, 37261, 37260, 37259, 37258, 37257, 37256, 37255, 37253, 37252, 37251, 37250, 37249, 37248];

    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $brand = Brand::query()->where('name', 'Moximu')->first();
        if ($brand === null) {
            return;
        }
        Suggestion::query()->where('brand_id', $brand->id)->where('status', Suggestion::APPROVED)
            ->whereKey(self::IDS)
            ->get()
            ->each(fn (Suggestion $idea) => $idea->forceFill(['status' => Suggestion::DISMISSED, 'resolved_at' => now(),
                'operator_note' => 'Marka rotası: eski moximu.com kurulumuna gönderilmiş taslak; site yeniden kurulduğu için sitede yok, kapatıldı.'])->save());
    }

    public function down(): void {}
};
