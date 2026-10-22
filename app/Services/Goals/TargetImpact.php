<?php

namespace App\Services\Goals;

use App\Models\BrandTarget;
use App\Services\Repair\RepairDesk;
use App\Support\SchemaState;

/**
 * "Bu iş ayda tahminen +3 talep" (Aşama 5): a desk package's expected effect on the brand's goal. Its pages' Search
 * Console clicks and impressions (90 days, a third of it a month) get the uplift its kind of fix usually brings, times
 * what fixes of that kind did here before (OutcomeTracker weight), times the brand's own leads per organic click.
 * An estimate, always worded as one; null when the brand has no measured web demand or the kind does not move search.
 */
final class TargetImpact
{
    /** Kind => [relative uplift of current clicks, extra clicks per impression]. */
    public const array UPLIFT = [
        RepairDesk::SITE_FIELDS => [0.10, 0.002],
        RepairDesk::SITE_CONTENT => [0.15, 0.003],
        RepairDesk::REDIRECT => [0.05, 0.001],
        RepairDesk::WEB_FIX => [0.05, 0.001],
        RepairDesk::IMAGE_ALT => [0.01, 0.0],
        RepairDesk::WEB_TASK => [0.02, 0.0],
    ];

    /** @var array<int, ?float>|null */
    private ?array $rates = null;

    /**
     * Expected extra leads a month of one package (RepairDesk::packages() shape).
     *
     * @param  array{brand_id: int, kind: string, impressions?: int, clicks?: int, weight?: float}  $package
     */
    public function forPackage(array $package): ?float
    {
        $uplift = self::UPLIFT[$package['kind']] ?? null;
        $rate = $this->rate((int) $package['brand_id']);
        if ($uplift === null || $rate === null) {
            return null;
        }
        $clicks = (int) ($package['clicks'] ?? 0) / 3;
        $impressions = (int) ($package['impressions'] ?? 0) / 3;
        $extraClicks = ($clicks * $uplift[0] + $impressions * $uplift[1]) * (float) ($package['weight'] ?? 1.0);

        return round($extraClicks * $rate, 1);
    }

    /** "+3 talep/ay" / "+0,4 talep/ay"; empty when there is no estimate or it rounds to nothing. */
    public static function label(?float $leads): string
    {
        if ($leads === null || $leads < 0.1) {
            return '';
        }

        return '+'.BrandTargets::number($leads).' talep/ay';
    }

    /** The brand's leads per organic click (BrandTargets basis), read once for all brands. */
    private function rate(int $brandId): ?float
    {
        if ($this->rates === null) {
            $this->rates = SchemaState::hasTable('brand_targets')
                ? BrandTarget::query()->get(['brand_id', 'basis'])->mapWithKeys(fn (BrandTarget $t): array => [(int) $t->brand_id => data_get($t->basis, 'web_leads_per_click')])->all()
                : [];
        }
        $rate = $this->rates[$brandId] ?? null;

        return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
    }
}
