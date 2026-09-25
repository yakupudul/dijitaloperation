<?php

namespace App\Services\Brain;

use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\SectorPackRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Brain's brake. Sector rules do not learn — they filter. Before a recommendation reaches an operator it passes:
 *  1. the brand's sector-pack text rules (forbidden expressions / claims) on its title and detail;
 *  2. method-level blocks: things a sector must not be told to do at all, whatever successful pages elsewhere do
 *     (health: show prices, run discount / urgency / testimonial-style angles);
 *  3. the legal gate (optional, `moxdop-brain.legal.health_paid_ads_gate`): paid-ads growth for health brands only
 *     with a recorded eligibility (first month, health tourism abroad, …).
 * A blocked recommendation is kept with status "blocked" and its reason, so the brake is visible, never silent.
 */
final class ComplianceBrake
{
    /** pack id => recommendation types (or type:angle) that are never recommended to brands of that pack */
    public const array BLOCKED = [
        'health' => ['website_method:has_price', 'meta_try_angle:price_offer', 'meta_try_angle:urgency', 'meta_try_angle:social_proof'],
        'finance' => ['meta_try_angle:urgency'],
        'food_supplement' => ['meta_try_angle:result_benefit'],
    ];

    /** Recommendation types that grow paid advertising (checked by the legal gate). */
    public const array PAID_GROWTH = ['ads_missing_cluster', 'meta_try_angle'];

    private const array SOURCES = ['website' => 'website', 'google_ads' => 'google_ads_ad', 'meta_ads' => 'meta_ad', 'google_business_profile' => 'gbp'];

    /** @var array<int, array{packs: list<string>, rules: Collection<int, ComplianceRule>}> */
    private array $brands = [];

    public function __construct(
        private readonly SectorPackRegistry $packs,
        private readonly ComplianceChecker $checker,
    ) {}

    /** Why the item must not be shown, or null when it may. @param  array<string, mixed>  $item */
    public function reason(array $item, ?int $brandId): ?string
    {
        if ($brandId === null) {
            return null;
        }
        $brand = $this->brand($brandId);
        if ($brand['packs'] === []) {
            return null;
        }
        $typeKey = $item['type'].(isset($item['evidence']['angle']) ? ':'.$item['evidence']['angle'] : '');
        foreach ($brand['packs'] as $pack) {
            if (in_array($typeKey, self::BLOCKED[$pack] ?? [], true) || in_array($item['type'], self::BLOCKED[$pack] ?? [], true)) {
                return 'Sektör kuralı: bu tür öneri '.$pack.' paketindeki markalara yapılmaz.';
            }
        }
        $hits = $this->checker->checkText(($item['title'] ?? '').' '.($item['detail'] ?? ''), $brand['rules'], self::SOURCES[$item['channel']] ?? 'ai_draft');
        foreach ($hits as $hit) {
            if (in_array($hit['rule']->severity, ['high', 'medium'], true)) {
                return 'Uyum kuralı: "'.$hit['rule']->label.'" ('.$hit['matched'].')';
            }
        }
        if ((int) config('moxdop-brain.legal.health_paid_ads_gate', 0) === 1 && in_array('health', $brand['packs'], true) && in_array($item['type'], self::PAID_GROWTH, true)) {
            $eligible = DB::table('brain_legal_eligibility')->where('brand_id', $brandId)->where('paid_ads_allowed', true)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))->exists();
            if (! $eligible) {
                return 'Yasal kapı: sağlık markası için ücretli reklam uygunluğu kayıtlı değil (Ayarlar → Sektör paketleri).';
            }
        }

        return null;
    }

    /** @return array{packs: list<string>, rules: Collection<int, ComplianceRule>} */
    private function brand(int $brandId): array
    {
        if (! isset($this->brands[$brandId])) {
            $brand = Brand::query()->find($brandId);
            $packs = $brand !== null ? array_map(fn ($p): string => $p->id(), $this->packs->forBrand($brand)) : [];
            $this->brands[$brandId] = ['packs' => $packs, 'rules' => $brand !== null && $packs !== [] ? $this->packs->rulesForBrand($brand) : collect()];
        }

        return $this->brands[$brandId];
    }
}
