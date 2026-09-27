<?php

namespace App\Services\Verification;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsRowScope;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Daily "is the collected data believable" check over the last complete days, per bound account. Reads stored
 * facts only, through the same scopes the alerts and advisors use (works on PostgreSQL compact facts, whose
 * logical tables are views):
 *
 * (a) missing days — GA4, Search Console, Google Ads, Meta Ads: a run of days without rows between days that
 *     have rows with real activity (a failed collection slice looks exactly like this);
 * (b) Google Ads spends but GA4 shows no google / cpc session on 3+ of those days (auto-tagging / GA4 link broken);
 * (c) Google Ads conversions vs GA4 key events of google / cpc sessions differ by more than 50% (both above a minimum);
 * (d) ad account currency differs from the currency the customer is invoiced in.
 *
 * Findings are upserted into data_consistency_issues by a stable key and resolved when a run no longer finds them.
 */
final class DataConsistencyChecker
{
    private const array LABELS = ['ga4' => 'GA4', 'search_console' => 'Search Console', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads'];

    /** Minimum activity on the days around a gap for the gap to count (a quiet account legitimately has empty days). */
    private const array GAP_MIN_ACTIVITY = ['ga4' => 5.0, 'search_console' => 10.0, 'google_ads' => 1.0, 'meta_ads' => 1.0];

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    public function __construct(
        private readonly SeoPlanInputCollector $seo,
        private readonly GoogleAdsSpecialistBindingResolver $adsBindings,
        private readonly MetaAdsSpecialistBindingResolver $metaBindings,
    ) {}

    /** @return array{open: int, new: int, resolved: int} */
    public function run(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now('Europe/Istanbul'))->startOfDay();
        $this->end = $today->subDay();
        $this->start = $this->end->subDays(max(3, (int) config('moxdop-verification.consistency.window_days', 14)) - 1);

        $issues = [];
        $failedAssets = [];
        $bindings = CoreAssetBinding::query()->with('digitalAsset')
            ->whereIn('capability', array_keys(self::LABELS))
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('digital_asset_id', DigitalAsset::query()->operational()->select('digital_assets.id'))
            ->orderBy('id')->get()
            ->filter(fn (CoreAssetBinding $binding): bool => $binding->digitalAsset !== null);

        /** @var array<int, array{ads: list<array{asset: DigitalAsset, days: array<string, array{spend: float, conversions: float}>}>, sites: list<DigitalAsset>}> $brands */
        $brands = [];
        foreach ($bindings as $binding) {
            $asset = $binding->digitalAsset;
            try {
                $series = $this->dailySeries($binding, $asset);
                if ($series === null) {
                    continue;
                }
                if (($gap = $this->missingDays($binding, $asset, $series)) !== null) {
                    $issues[] = $gap;
                }
                $brandId = (int) $asset->brand_id;
                if ($binding->capability === 'google_ads' && $brandId > 0) {
                    $brands[$brandId]['ads'][] = ['asset' => $asset, 'days' => $series];
                }
                if ($binding->capability === 'ga4' && $brandId > 0) {
                    $brands[$brandId]['sites'][] = $asset;
                }
                if (in_array($binding->capability, ['google_ads', 'meta_ads'], true) && ($currency = $this->currencyMismatch($binding, $asset)) !== null) {
                    $issues[] = $currency;
                }
            } catch (Throwable $error) {
                report($error);
                $failedAssets[(int) $asset->id] = true;
            }
        }

        foreach ($brands as $brandId => $brand) {
            if (($brand['ads'] ?? []) === [] || ($brand['sites'] ?? []) === []) {
                continue;
            }
            try {
                array_push($issues, ...$this->adsVersusGa4($brandId, $brand['ads'], $brand['sites']));
            } catch (Throwable $error) {
                report($error);
                foreach ($brand['ads'] as $ads) {
                    $failedAssets[(int) $ads['asset']->id] = true;
                }
            }
        }

        return $this->persist($issues, array_keys($failedAssets));
    }

    /**
     * Per-day totals for the window, keyed by Y-m-d; null when the binding cannot be read (demo / not really bound).
     *
     * @return array<string, array<string, float>>|null
     */
    private function dailySeries(CoreAssetBinding $binding, DigitalAsset $asset): ?array
    {
        [$from, $to] = [$this->start->toDateString(), $this->end->toDateString()];
        $query = match ($binding->capability) {
            'ga4' => Schema::hasTable('ga4_property_daily')
                ? $this->seo->scopeGa4(DB::table('ga4_property_daily'), $asset)->whereBetween('reporting_date', [$from, $to])
                    ->selectRaw('reporting_date as day, sum(sessions) as activity')
                : null,
            'search_console' => Schema::hasTable('gsc_property_daily')
                ? $this->seo->scopeGsc(DB::table('gsc_property_daily'), $asset, 'gsc_property_daily')->whereBetween('reporting_date', [$from, $to])
                    ->selectRaw('reporting_date as day, sum(impressions) as activity')
                : null,
            'google_ads' => $this->adsScope($asset)?->daily('google_ads_account_daily', $from, $to)
                ->selectRaw('reporting_date as day, sum(cost_amount) as activity, sum(conversions) as conversions'),
            'meta_ads' => $this->metaQuery($asset, $from, $to)?->selectRaw('reporting_date as day, sum(spend) as activity'),
            default => null,
        };
        if (! $query instanceof Builder) {
            return null;
        }
        $out = [];
        foreach ($query->groupBy('reporting_date')->get() as $row) {
            $out[substr((string) $row->day, 0, 10)] = ['activity' => (float) $row->activity, 'conversions' => (float) ($row->conversions ?? 0)];
        }
        ksort($out);

        return $out;
    }

    private function adsScope(DigitalAsset $asset): ?GoogleAdsRowScope
    {
        $binding = $this->adsBindings->resolve((string) $asset->id);

        return $binding->isReal() && $binding->externalResourceId !== null
            ? new GoogleAdsRowScope((int) $asset->id, (int) $binding->externalResourceId, (string) $binding->customerId)
            : null;
    }

    private function metaQuery(DigitalAsset $asset, string $from, string $to): ?Builder
    {
        $binding = $this->metaBindings->resolve((string) $asset->id);
        if (! $binding->isReal() || ! Schema::hasTable('meta_account_daily')) {
            return null;
        }

        return DB::table('meta_account_daily')
            ->where('account_id', (string) $binding->accountId)
            ->where(fn (Builder $scope) => $scope->where('digital_asset_id', $asset->id)
                ->orWhere(fn (Builder $central) => $central->whereNull('digital_asset_id')->where('external_resource_id', $binding->externalResourceId)))
            ->whereBetween('reporting_date', [$from, $to]);
    }

    /**
     * (a) Days without rows between days that have rows with activity.
     *
     * @param  array<string, array<string, float>>  $series
     * @return array<string, mixed>|null
     */
    private function missingDays(CoreAssetBinding $binding, DigitalAsset $asset, array $series): ?array
    {
        if (count($series) < 2) {
            return null;
        }
        $min = self::GAP_MIN_ACTIVITY[$binding->capability] ?? 1.0;
        $days = [];
        for ($day = $this->start; $day->lte($this->end); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }
        $missing = [];
        $run = [];
        $before = null;
        foreach ($days as $day) {
            if (! isset($series[$day])) {
                $run[] = $day;

                continue;
            }
            // A gap counts only when both sides of it have rows with real activity.
            if ($run !== [] && $before !== null && $series[$before]['activity'] >= $min && $series[$day]['activity'] >= $min) {
                array_push($missing, ...$run);
            }
            $run = [];
            $before = $day;
        }
        if ($missing === []) {
            return null;
        }
        $label = self::LABELS[$binding->capability];

        return $this->issue('missing_days:'.$binding->capability.':'.$asset->id.':'.$binding->external_resource_id, 'missing_days', 'medium', $asset,
            $label.': '.count($missing).' günde veri eksik',
            sprintf('%s hesabında %s tarihlerinde hiç satır yok, önceki ve sonraki günlerde veri var. Toplama o günleri atlamış olabilir: Sistem Sağlığı › Hesaplar tablosundan "Veri setleri"ni kontrol edin ve gerekirse "Şimdi çek" ile yeniden çekin.',
                $label, implode(', ', array_map(fn (string $d): string => CarbonImmutable::parse($d)->format('d.m'), $missing))),
            ['capability' => $binding->capability, 'dates' => $missing, 'external_resource_id' => $binding->external_resource_id]);
    }

    /**
     * (b) spend without paid GA4 sessions and (c) Ads conversions vs GA4 paid key events, per Google Ads account of the brand.
     *
     * @param  list<array{asset: DigitalAsset, days: array<string, array<string, float>>}>  $ads
     * @param  list<DigitalAsset>  $sites
     * @return list<array<string, mixed>>
     */
    private function adsVersusGa4(int $brandId, array $ads, array $sites): array
    {
        $ga4 = $this->ga4Paid($sites);
        if ($ga4 === null) {
            return [];
        }
        $cfg = (array) config('moxdop-verification.consistency');
        $issues = [];
        $spendByDay = [];
        $adsConversions = 0.0;
        foreach ($ads as $account) {
            foreach ($account['days'] as $day => $row) {
                $spendByDay[$day] = ($spendByDay[$day] ?? 0.0) + $row['activity'];
                $adsConversions += $row['conversions'];
            }
        }
        $anchor = $ads[0]['asset'];

        $untagged = [];
        foreach ($spendByDay as $day => $spend) {
            if ($spend >= (float) ($cfg['untagged_min_daily_spend'] ?? 1.0) && ($ga4['sessions'][$day] ?? 0.0) > 0 && ($ga4['paid'][$day] ?? 0.0) <= 0.0) {
                $untagged[] = $day;
            }
        }
        if (count($untagged) >= (int) ($cfg['untagged_min_days'] ?? 3)) {
            $issues[] = $this->issue('ads_untagged:'.$brandId, 'ads_untagged', 'high', $anchor,
                'Google Ads harcıyor ama GA4\'te reklam trafiği görünmüyor',
                sprintf('%d gün Google Ads harcama yaptı, GA4\'te aynı günlerde google / cpc oturumu yok (%s). Otomatik etiketleme (gclid) kapalı, GA4–Google Ads bağlantısı kopuk ya da sitedeki GA4 etiketi eksik olabilir.',
                    count($untagged), implode(', ', array_map(fn (string $d): string => CarbonImmutable::parse($d)->format('d.m'), $untagged))),
                ['dates' => $untagged]);
        }

        $ga4KeyEvents = array_sum($ga4['key_events']);
        $min = (float) ($cfg['conversion_min'] ?? 10);
        if ($ga4['has_key_events'] && $adsConversions >= $min && $ga4KeyEvents >= $min) {
            $divergence = abs($adsConversions - $ga4KeyEvents) / max($adsConversions, $ga4KeyEvents) * 100;
            if ($divergence > (float) ($cfg['conversion_divergence_pct'] ?? 50)) {
                $issues[] = $this->issue('conversion_divergence:'.$brandId, 'conversion_divergence', 'medium', $anchor,
                    'Google Ads ve GA4 dönüşümleri tutmuyor',
                    sprintf('Son %d günde Google Ads %s dönüşüm, GA4 (google / cpc oturumları) %s anahtar olay sayıyor (fark %%%d). Dönüşüm işlemleri, içe aktarılan GA4 olayları veya sayım ayarı (her biri / bir) kontrol edilmeli.',
                        $this->start->diffInDays($this->end) + 1, number_format($adsConversions, 0, ',', '.'), number_format($ga4KeyEvents, 0, ',', '.'), (int) round($divergence)),
                    ['ads_conversions' => round($adsConversions, 2), 'ga4_key_events' => round($ga4KeyEvents, 2), 'divergence_pct' => round($divergence, 1)]);
            }
        }

        return $issues;
    }

    /**
     * GA4 per-day property sessions, google / cpc sessions and their key events over all GA4-bound sites of a brand.
     * Null when source / medium data is not collected for any of the sites (then nothing can be concluded).
     *
     * @param  list<DigitalAsset>  $sites
     * @return array{sessions: array<string, float>, paid: array<string, float>, key_events: array<string, float>, has_key_events: bool}|null
     */
    private function ga4Paid(array $sites): ?array
    {
        if (! Schema::hasTable('ga4_source_medium_daily') || ! Schema::hasTable('ga4_property_daily')) {
            return null;
        }
        [$from, $to] = [$this->start->toDateString(), $this->end->toDateString()];
        $mediums = array_map('strtolower', (array) config('moxdop-verification.consistency.paid_mediums', ['cpc']));
        $hasKeyEvents = Schema::hasColumn('ga4_source_medium_daily', 'keyEvents');
        $out = ['sessions' => [], 'paid' => [], 'key_events' => [], 'has_key_events' => $hasKeyEvents];
        $seen = false;
        foreach ($sites as $site) {
            foreach ($this->seo->scopeGa4(DB::table('ga4_property_daily'), $site)->whereBetween('reporting_date', [$from, $to])
                ->selectRaw('reporting_date as day, sum(sessions) as sessions')->groupBy('reporting_date')->get() as $row) {
                $day = substr((string) $row->day, 0, 10);
                $out['sessions'][$day] = ($out['sessions'][$day] ?? 0.0) + (float) $row->sessions;
            }
            $query = $this->seo->scopeGa4(DB::table('ga4_source_medium_daily'), $site)->whereBetween('reporting_date', [$from, $to]);
            $grammar = $query->getGrammar();
            $select = 'reporting_date as day, '.$grammar->wrap('sessionSource').' as source, '.$grammar->wrap('sessionMedium').' as medium, sum(sessions) as sessions'
                .($hasKeyEvents ? ', sum('.$grammar->wrap('keyEvents').') as key_events' : '');
            foreach ($query->selectRaw($select)->groupBy('reporting_date', 'sessionSource', 'sessionMedium')->get() as $row) {
                $seen = true;
                $day = substr((string) $row->day, 0, 10);
                if (! str_contains(strtolower((string) $row->source), 'google') || ! in_array(strtolower(trim((string) $row->medium)), $mediums, true)) {
                    continue;
                }
                $out['paid'][$day] = ($out['paid'][$day] ?? 0.0) + (float) $row->sessions;
                $out['key_events'][$day] = ($out['key_events'][$day] ?? 0.0) + (float) ($row->key_events ?? 0);
            }
        }

        return $seen ? $out : null;
    }

    /** (d) The ad account's currency is not the currency the customer is invoiced in. */
    private function currencyMismatch(CoreAssetBinding $binding, DigitalAsset $asset): ?array
    {
        $context = $binding->capability === 'google_ads' ? $this->adsBindings->resolve((string) $asset->id) : $this->metaBindings->resolve((string) $asset->id);
        $accountCurrency = strtoupper(trim((string) ($context->currency ?? '')));
        if (! $context->isReal() || $accountCurrency === '' || $accountCurrency === 'XXX' || ! Schema::hasTable('agency_invoices')) {
            return null;
        }
        $customerId = DB::table('brands')->where('id', $asset->brand_id)->value('customer_id');
        $billing = $customerId !== null
            ? strtoupper(trim((string) DB::table('agency_invoices')->where('customer_id', $customerId)->whereNot('status', 'cancelled')->orderByDesc('period')->orderByDesc('id')->value('currency')))
            : '';
        if ($billing === '' || $billing === $accountCurrency) {
            return null;
        }
        $label = self::LABELS[$binding->capability];

        return $this->issue('currency_mismatch:'.$binding->capability.':'.$asset->id, 'currency_mismatch', 'low', $asset,
            $label.' hesabının para birimi faturadan farklı',
            sprintf('%s hesabı %s ile harcıyor, müşteri %s ile faturalanıyor. Raporlardaki harcama ve bütçe karşılaştırmalarında kur farkını hesaba katın ya da hesabın doğru müşteriye bağlı olduğunu kontrol edin.', $label, $accountCurrency, $billing),
            ['account_currency' => $accountCurrency, 'billing_currency' => $billing]);
    }

    /** @return array<string, mixed> */
    private function issue(string $key, string $kind, string $severity, DigitalAsset $asset, string $title, string $detail, array $data): array
    {
        return ['issue_key' => $key, 'kind' => $kind, 'severity' => $severity, 'brand_id' => $asset->brand_id, 'digital_asset_id' => $asset->id,
            'title' => $title, 'detail' => $detail, 'data' => $data + ['window' => [$this->start->toDateString(), $this->end->toDateString()]]];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<int>  $failedAssetIds  assets whose check threw: their open issues are kept as they are
     * @return array{open: int, new: int, resolved: int}
     */
    private function persist(array $issues, array $failedAssetIds): array
    {
        $new = 0;
        $keys = [];
        foreach ($issues as $issue) {
            $keys[] = $issue['issue_key'];
            $existing = DB::table('data_consistency_issues')->where('issue_key', $issue['issue_key'])->first();
            $reopened = $existing === null || $existing->resolved_at !== null;
            $new += $reopened ? 1 : 0;
            $values = [...$issue, 'data' => json_encode($issue['data'], JSON_UNESCAPED_UNICODE), 'last_detected_at' => now(), 'resolved_at' => null, 'updated_at' => now()];
            if ($existing === null) {
                DB::table('data_consistency_issues')->insert($values + ['first_detected_at' => now(), 'created_at' => now()]);
            } else {
                DB::table('data_consistency_issues')->where('id', $existing->id)->update($values + ($reopened ? ['first_detected_at' => now()] : []));
            }
        }
        $resolved = DB::table('data_consistency_issues')->whereNull('resolved_at')
            ->when($keys !== [], fn (Builder $query) => $query->whereNotIn('issue_key', $keys))
            ->when($failedAssetIds !== [], fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('digital_asset_id')->orWhereNotIn('digital_asset_id', $failedAssetIds)))
            ->update(['resolved_at' => now(), 'updated_at' => now()]);

        return ['open' => count($keys), 'new' => $new, 'resolved' => $resolved];
    }

    /** @return Collection<int, object> open issues with brand and asset names, newest first */
    public static function open(): Collection
    {
        return app(ServiceScope::class)->constrain(DB::table('data_consistency_issues as i'), 'i.digital_asset_id', 'i.brand_id')
            ->leftJoin('brands as b', 'b.id', '=', 'i.brand_id')
            ->leftJoin('digital_assets as a', 'a.id', '=', 'i.digital_asset_id')
            ->whereNull('i.resolved_at')
            ->orderByDesc('i.last_detected_at')->limit(300)
            ->get(['i.*', 'b.name as brand_name', 'a.name as asset_name', 'a.domain as asset_domain', 'a.type as asset_type']);
    }
}
