<?php

namespace App\Services\GoogleAds;

use App\Models\DigitalAsset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Google Ads lead quality (spec Faz 5/6: form geldi · uygun müşteri · randevu · satış): the operator enters monthly
 * counts per campaign on Ölçümleme. They are shown next to the Google Ads conversions of the same month, never added
 * to them, and go into the structure / budget AI pack as the operator's CRM numbers.
 */
final class GoogleAdsLeadQuality
{
    public const array FIELDS = ['leads' => 'Form geldi', 'qualified' => 'Uygun müşteri', 'appointments' => 'Randevu', 'sales' => 'Satış'];

    public function __construct(private readonly GoogleAdsScreen $screen) {}

    /**
     * Campaigns with Google Ads data in the month (or an entry), with the month's Google Ads conversions and the entry.
     *
     * @return list<array{campaign_id: string, name: string, ads_conversions: ?float, entry: ?array<string, int>}>
     */
    public function month(DigitalAsset $asset, string $month): array
    {
        $start = self::monthStart($month);
        $entries = DB::table('google_ads_lead_quality')->where('digital_asset_id', $asset->id)->where('month', $start->toDateString())->get()->keyBy('campaign_id');
        $ctx = $this->screen->context($asset);
        $rows = [];
        if ($ctx !== null) {
            $names = $this->screen->names($ctx['scope'])['campaigns'];
            $ctx['scope']->daily('google_ads_campaign_daily', $start->toDateString(), $start->endOfMonth()->toDateString())
                ->groupBy('campaign_id')->selectRaw('campaign_id, SUM(conversions) as conversions, SUM(cost_amount) as cost')->get()
                ->each(function (object $r) use (&$rows, $names): void {
                    $id = (string) $r->campaign_id;
                    $rows[$id] = ['campaign_id' => $id, 'name' => $names[$id]['name'] ?? ('Kampanya '.$id), 'ads_conversions' => round((float) $r->conversions, 1), 'cost' => (float) $r->cost];
                });
        }
        foreach ($entries as $id => $entry) {
            $rows[(string) $id] ??= ['campaign_id' => (string) $id, 'name' => (string) ($entry->campaign_name ?: 'Kampanya '.$id), 'ads_conversions' => null, 'cost' => 0.0];
        }
        uasort($rows, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return array_values(array_map(function (array $row) use ($entries): array {
            $entry = $entries->get($row['campaign_id']);
            unset($row['cost']);

            return $row + ['entry' => $entry === null ? null : array_map('intval', array_intersect_key((array) $entry, self::FIELDS))];
        }, $rows));
    }

    /** @param  array<string, mixed>  $counts */
    public function save(DigitalAsset $asset, string $month, string $campaignId, string $campaignName, array $counts, ?User $user): void
    {
        $start = self::monthStart($month);
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $counts[$field] ?? 0;
            if (! is_numeric($value) || (int) $value < 0 || (int) $value > 100000) {
                throw ValidationException::withMessages(['lead' => self::FIELDS[$field].': 0 veya daha büyük bir sayı girin.']);
            }
            $values[$field] = (int) $value;
        }
        if ($values['qualified'] > $values['leads'] || $values['appointments'] > $values['leads'] || $values['sales'] > $values['leads']) {
            throw ValidationException::withMessages(['lead' => 'Uygun, randevu ve satış, gelen formdan fazla olamaz.']);
        }
        DB::table('google_ads_lead_quality')->updateOrInsert(
            ['digital_asset_id' => $asset->id, 'campaign_id' => mb_substr($campaignId, 0, 40), 'month' => $start->toDateString()],
            $values + ['campaign_name' => mb_substr($campaignName, 0, 300), 'updated_by' => $user?->id, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Last $months full and current months, summed per campaign — the operator's CRM numbers for the AI pack.
     *
     * @return list<array{campaign: string, months: int, leads: int, qualified: int, appointments: int, sales: int}>
     */
    public function recent(DigitalAsset $asset, int $months = 3): array
    {
        return DB::table('google_ads_lead_quality')->where('digital_asset_id', $asset->id)
            ->where('month', '>=', now()->startOfMonth()->subMonths($months - 1)->toDateString())
            ->groupBy('campaign_id')
            ->selectRaw('campaign_id, MAX(campaign_name) as campaign_name, COUNT(*) as months, SUM(leads) as leads, SUM(qualified) as qualified, SUM(appointments) as appointments, SUM(sales) as sales')
            ->orderBy('campaign_id')->get()
            ->map(fn (object $r): array => ['campaign' => (string) ($r->campaign_name ?: $r->campaign_id), 'months' => (int) $r->months, 'leads' => (int) $r->leads,
                'qualified' => (int) $r->qualified, 'appointments' => (int) $r->appointments, 'sales' => (int) $r->sales])->all();
    }

    /** @return list<string> the current and the previous 11 months (Y-m) */
    public static function monthOptions(): array
    {
        return array_map(fn (int $i): string => now()->startOfMonth()->subMonths($i)->format('Y-m'), range(0, 11));
    }

    private static function monthStart(string $month): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1 || ! in_array($month, self::monthOptions(), true)) {
            throw ValidationException::withMessages(['lead' => 'Geçerli bir ay seçin.']);
        }

        return CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
    }
}
