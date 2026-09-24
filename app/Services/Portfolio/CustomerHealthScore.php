<?php

namespace App\Services\Portfolio;

use App\Enums\CustomerStatus;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Customer;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Measurement\BrandMeasurementScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Müşteri sağlığı puanı (Faz 10b): 100 minus penalties for what usually precedes losing a client — results falling
 * (conversions, search clicks, site sessions over the last 28 days vs the 28 before), no contact for 30+ days,
 * a renewal due soon or an unpaid one, open critical / high alerts. Every penalty is listed as a reason.
 * Stored data only; recomputed daily.
 */
final class CustomerHealthScore
{
    public function __construct(private readonly BrandConversionDictionary $conversions) {}

    public static function band(int $score): string
    {
        return $score >= 80 ? 'good' : ($score >= 60 ? 'watch' : 'risk');
    }

    /** @return array{score: int, band: string, reasons: list<array{points: int, text: string}>} */
    public function compute(Customer $customer): array
    {
        $cfg = (array) config('moxdop-assistant.health', []);
        $reasons = [];
        $brands = Brand::query()->where('customer_id', $customer->id)->get();
        $end = CarbonImmutable::now()->subDays(2)->startOfDay();
        $mid = $end->subDays(28);
        $start = $mid->subDays(28);
        $drop = (float) ($cfg['drop_share'] ?? 0.2);

        foreach ($brands as $brand) {
            $scope = BrandMeasurementScope::for($brand);
            try {
                $now = (float) $this->conversions->totals($brand, $mid->addDay(), $end->endOfDay())['total'];
                $before = (float) $this->conversions->totals($brand, $start->addDay(), $mid->endOfDay())['total'];
            } catch (Throwable) {
                $now = $before = 0.0;
            }
            if ($before >= (float) ($cfg['min_conversions'] ?? 5) && $now < $before * (1 - $drop)) {
                $reasons[] = ['points' => (int) ($cfg['conversion_drop_points'] ?? 20), 'text' => sprintf('%s: dönüşüm son 28 günde %%%d düştü (%s → %s).', $brand->name, (int) round((1 - $now / $before) * 100), self::n($before), self::n($now))];
            }
            foreach (['gsc_property_daily' => ['sum(clicks)', 'Google arama tıklaması', 50], 'ga4_property_daily' => ['sum(sessions)', 'site oturumu', 100]] as $table => [$expression, $label, $minimum]) {
                if ($scope->isEmpty() || ! Schema::hasTable($table)) {
                    continue;
                }
                $sum = fn (CarbonImmutable $from, CarbonImmutable $to): float => (float) $scope->apply(DB::table($table))->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])->selectRaw($expression.' as v')->value('v');
                $recent = $sum($mid->addDay(), $end);
                $previous = $sum($start->addDay(), $mid);
                if ($previous >= $minimum && $recent < $previous * (1 - $drop)) {
                    $reasons[] = ['points' => (int) ($cfg['traffic_drop_points'] ?? 10), 'text' => sprintf('%s: %s %%%d düştü (%s → %s).', $brand->name, $label, (int) round((1 - $recent / $previous) * 100), self::n($previous), self::n($recent))];
                }
            }
        }

        if (Schema::hasColumn('whatsapp_conversations', 'customer_id')) {
            $last = DB::table('whatsapp_conversations')->where('customer_id', $customer->id)->max('last_message_at');
            $silentDays = (int) ($cfg['silent_days'] ?? 30);
            if ($last !== null && strtotime((string) $last) < now()->subDays($silentDays)->getTimestamp()) {
                $reasons[] = ['points' => (int) ($cfg['silence_points'] ?? 15), 'text' => sprintf('%d+ gündür WhatsApp teması yok (son: %s).', $silentDays, CarbonImmutable::parse((string) $last)->format('d.m.Y'))];
            }
        }

        $brandIds = $brands->pluck('id');
        if (Schema::hasTable('asset_renewals') && $brandIds->isNotEmpty()) {
            $due = DB::table('asset_renewals')->whereIn('brand_id', $brandIds)->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(14)->toDateString())
                ->where('expires_on', '>=', now()->subDays(30)->toDateString())->where('auto_renew', false)->count();
            if ($due > 0) {
                $reasons[] = ['points' => (int) ($cfg['renewal_points'] ?? 10), 'text' => $due.' yenileme 14 gün içinde (otomatik yenilenmiyor).'];
            }
            $unpaid = DB::table('asset_renewals')->whereIn('brand_id', $brandIds)->where('collection_status', 'billed')->where('expires_on', '<', now()->toDateString())->count();
            if ($unpaid > 0) {
                $reasons[] = ['points' => (int) ($cfg['unpaid_points'] ?? 10), 'text' => $unpaid.' yenileme faturalandı ama ödenmedi.'];
            }
        }

        $alerts = AssetAlert::query()->open()->whereIn('brand_id', $brandIds)->whereIn('severity', ['critical', 'high'])->get(['severity', 'title']);
        if ($alerts->where('severity', 'critical')->isNotEmpty()) {
            $reasons[] = ['points' => (int) ($cfg['critical_alert_points'] ?? 15), 'text' => 'Kritik uyarı: '.$alerts->where('severity', 'critical')->pluck('title')->unique()->take(2)->implode(', ')];
        } elseif ($alerts->isNotEmpty()) {
            $reasons[] = ['points' => (int) ($cfg['high_alert_points'] ?? 5), 'text' => 'Yüksek öncelikli uyarı: '.$alerts->pluck('title')->unique()->take(2)->implode(', ')];
        }

        usort($reasons, static fn (array $a, array $b): int => $b['points'] <=> $a['points']);
        $score = max(0, 100 - array_sum(array_column($reasons, 'points')));

        return ['score' => $score, 'band' => self::band($score), 'reasons' => $reasons];
    }

    /** @return array{computed: int} */
    public function recomputeAll(): array
    {
        $count = 0;
        foreach (Customer::query()->where('status', CustomerStatus::Active->value)->get() as $customer) {
            $this->store($customer);
            $count++;
        }
        DB::table('customer_health')->whereIn('customer_id', Customer::query()->where('status', '!=', CustomerStatus::Active->value)->select('id'))->delete();

        return ['computed' => $count];
    }

    /** @return array{score: int, band: string, reasons: list<array{points: int, text: string}>} */
    public function store(Customer $customer): array
    {
        $result = $this->compute($customer);
        DB::table('customer_health')->updateOrInsert(['customer_id' => $customer->id], [
            'score' => $result['score'], 'band' => $result['band'], 'reasons' => json_encode($result['reasons'], JSON_UNESCAPED_UNICODE),
            'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $result;
    }

    private static function n(float $value): string
    {
        return number_format($value, $value >= 100 ? 0 : 1, ',', '.');
    }
}
