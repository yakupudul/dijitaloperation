<?php

namespace App\Services\Finance;

use App\Models\AgencySetting;
use App\Services\Operator\AgencySettingService;

/**
 * What Finans keeps about the agency itself (agency_settings.finance): our own vergi / TC no and names (an invoice
 * whose seller is us is one we wrote), the KDV rate, the yearly inflation the "erime" column counts with, and where
 * the Gmail reading stands.
 */
final class FinanceSettings
{
    public const array DEFAULTS = ['own_tax_no' => '', 'own_names' => ['moximu', 'yakup udül'], 'vat' => 20, 'inflation' => 0.0, 'gmail' => [],
        // Monthly list prices (₺, KDV hariç) the "liste" column counts with: moximu.com's public prices of 2026-11-03.
        'list' => ['seo_gbp' => 14000, 'seo' => 12000, 'gbp' => 6000, 'google_ads' => 9000, 'meta_ads' => 9000, 'ads_with_package' => 6000]];

    /** @return array{own_tax_no: string, own_names: list<string>, vat: int, inflation: float, gmail: array<string, mixed>} */
    public function all(): array
    {
        $kept = (array) (AgencySetting::query()->orderBy('id')->first()?->finance ?? []);
        $out = array_merge(self::DEFAULTS, array_intersect_key($kept, self::DEFAULTS));
        $out['own_tax_no'] = (string) preg_replace('/\D+/', '', (string) $out['own_tax_no']);
        $out['own_names'] = array_values(array_unique(array_filter(array_map(fn ($n): string => mb_strtolower(trim((string) $n)), [...self::DEFAULTS['own_names'], ...(array) $out['own_names']]))));
        $out['vat'] = max(0, min(40, (int) $out['vat']));
        $out['inflation'] = max(0.0, min(500.0, (float) $out['inflation']));
        $out['gmail'] = (array) $out['gmail'];
        $out['list'] = array_map('intval', array_merge(self::DEFAULTS['list'], array_intersect_key((array) $out['list'], self::DEFAULTS['list'])));

        return $out;
    }

    public function get(string $key): mixed
    {
        return data_get($this->all(), $key);
    }

    /** @param  array<string, mixed>  $values */
    public function put(array $values): void
    {
        $service = app(AgencySettingService::class);
        $setting = AgencySetting::query()->whereKey($service->current()->id)->firstOrFail();
        $kept = (array) ($setting->finance ?? []);
        foreach ($values as $key => $value) {
            data_set($kept, $key, $value);
        }
        $setting->forceFill(['finance' => $kept])->save();
        $service->forget();
    }

    /**
     * What the services of an agreement cost a month at today's list (null when the services are not written or have
     * no list price). Ads beside the SEO package take the package price.
     *
     * @param  ?array<int, string>  $services
     */
    public function listPrice(?array $services): ?int
    {
        $services = array_values(array_unique((array) $services));
        $list = $this->all()['list'];
        $package = in_array('seo_gbp', $services, true) || (in_array('seo', $services, true) && in_array('gbp', $services, true));
        $sum = 0;
        foreach ($services as $service) {
            if ($package && in_array($service, ['seo', 'gbp'], true)) {
                continue;
            }
            $sum += match ($service) {
                'google_ads', 'meta_ads' => $package ? $list['ads_with_package'] : $list[$service],
                'seo_gbp', 'seo', 'gbp' => $list[$service],
                default => 0,
            };
        }
        if ($package && ! in_array('seo_gbp', $services, true)) {
            $sum += $list['seo_gbp'];
        }

        return $sum > 0 ? $sum : null;
    }

    /** 1.2 for %20. */
    public function vatFactor(): float
    {
        return 1 + $this->all()['vat'] / 100;
    }
}
