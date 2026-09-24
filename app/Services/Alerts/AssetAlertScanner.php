<?php

namespace App\Services\Alerts;

use App\Models\AssetAlert;
use App\Models\AssetRenewal;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Services\Advisor\GoogleAds\GoogleAdsRowScope;
use App\Services\Assistant\PushNotifier;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Measurement\TrackingHealthChecker;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\Operations\SystemHealthReader;
use App\Services\Operator\AssetRuntimeStatusReader;
use App\Services\SeoTasks\SeoPlanInputCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Daily scan that turns collected data into a few time-sensitive alerts per asset:
 * Google Ads / Meta spend spike and delivery stop, Google Ads conversions stopped, website search traffic
 * drop (Search Console), website tracking health (tags, GA4 data, website conversions), stale data on a bound
 * account, low-rated unanswered Business Profile reviews, and (budget watch) ad budget / balance ran out,
 * account blocked, campaign daily budget used up early, disapproved ads.
 * Alerts are upserted by a stable key and resolved when a scan no longer detects them.
 */
final class AssetAlertScanner
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $adsBindings,
        private readonly MetaAdsSpecialistBindingResolver $metaBindings,
        private readonly SeoPlanInputCollector $seoInputs,
        private readonly AssetRuntimeStatusReader $runtime,
        private readonly TrackingHealthChecker $tracking,
    ) {}

    /** @return array{assets: int, open: int, new: int, resolved: int} */
    public function scanAll(): array
    {
        $totals = ['assets' => 0, 'open' => 0, 'new' => 0, 'resolved' => 0];
        DigitalAsset::query()
            ->operational()
            ->whereIn('type', ['google_ads', 'meta_ads', 'website', 'google_business_profile', 'gbp'])
            ->orderBy('id')
            ->chunk(100, function (Collection $assets) use (&$totals): void {
                $runtime = $this->runtime->forAssets($assets);
                foreach ($assets as $asset) {
                    $result = $this->scan($asset, $runtime[(int) $asset->id] ?? []);
                    $totals['assets']++;
                    foreach (['open', 'new', 'resolved'] as $key) {
                        $totals[$key] += $result[$key];
                    }
                }
            });

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $runtime  AssetRuntimeStatusReader row for this asset
     * @return array{open: int, new: int, resolved: int}
     */
    public function scan(DigitalAsset $asset, array $runtime = []): array
    {
        $detected = [];
        try {
            $detected = match ((string) $asset->type) {
                'google_ads' => $this->googleAds($asset),
                'meta_ads' => $this->metaAds($asset),
                'website' => [...$this->website($asset), ...$this->ga4Drops($asset)],
                'google_business_profile', 'gbp' => $this->businessProfile($asset),
                default => [],
            };
        } catch (Throwable $exception) {
            report($exception);
        }
        if ((string) $asset->type === 'website') {
            try {
                array_push($detected, ...$this->tracking->check($asset));
                array_push($detected, ...$this->wordpressConnector($asset));
                array_push($detected, ...$this->renewals($asset));
                // Faz 6: keep the uptime monitor's open site_down alert while the site is still down.
                if (DB::table('uptime_states')->where('digital_asset_id', $asset->id)->value('state') === 'down') {
                    $open = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->where('kind', 'site_down')->first();
                    if ($open !== null) {
                        $detected[] = $this->alert('site_down', 'critical', (string) $open->title, (string) $open->message, (array) $open->data);
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        if (($runtime['connected'] ?? false) && ($runtime['data_state'] ?? '') === 'stale') {
            $hours = (int) config('moxdop-alerts.stale_data_hours', 72);
            $detected[] = $this->alert('stale_data', 'medium', 'Veri güncel değil',
                sprintf('Bağlı hesaptan son veri %s geldi (%d saatten eski). Veri Kaynakları sayfasından veri çekimini kontrol edin.', (string) ($runtime['last_update'] ?? '—'), $hours));
        }

        // Faz 13: GA4 and Search Console are checked per account, so a fresh website crawl no longer hides a stale one.
        foreach ($this->staleMeasurementAccounts($asset) as $alert) {
            $detected[] = $alert;
        }

        return $this->persist($asset, $detected);
    }

    /** @return list<array{kind: string, severity: string, title: string, message: string, data: array<string, mixed>}> */
    private function staleMeasurementAccounts(DigitalAsset $asset): array
    {
        $labels = ['ga4' => 'GA4', 'search_console' => 'Search Console'];
        $staleDays = (int) config('moxdop-observability.account_stale_days', 3);
        $alerts = [];
        $bindings = CoreAssetBinding::query()->where('digital_asset_id', $asset->id)->whereIn('capability', array_keys($labels))
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->get(['capability', 'external_resource_id']);
        foreach ($bindings as $binding) {
            $automation = ResourceAutomation::query()->where('external_resource_id', $binding->external_resource_id)->first();
            if ($automation === null || ! $automation->collection_enabled) {
                continue;
            }
            $limit = now()->subDays(max(1, (int) $automation->interval_days) + $staleDays);
            $last = $automation->last_collection_success_at;
            if (($last !== null && $last->lt($limit)) || ($last === null && $automation->created_at !== null && $automation->created_at->lt($limit))) {
                $label = $labels[$binding->capability];
                $alerts[] = $this->alert($binding->capability === 'ga4' ? 'ga4_stale' : 'gsc_stale', 'medium', $label.' verisi güncel değil',
                    sprintf('%s hesabından son başarılı veri çekimi %s. Sistem Sağlığı › Hesaplar tablosundan durumu kontrol edin.', $label, $last?->timezone('Europe/Istanbul')->format('d.m.Y') ?? 'hiç yapılmadı'),
                    ['resource_id' => (int) $binding->external_resource_id]);
            }
        }

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function googleAds(DigitalAsset $asset): array
    {
        $binding = $this->adsBindings->resolve((string) $asset->id);
        if (! $binding->isReal()) {
            return [];
        }
        $scope = new GoogleAdsRowScope((int) $asset->id, (int) $binding->externalResourceId, (string) $binding->customerId);
        $currency = (string) ($binding->currency ?: '');
        $days = $this->dailySeries($scope->daily('google_ads_account_daily', now()->subDays(40)->toDateString(), now()->toDateString())
            ->selectRaw('reporting_date, sum(cost_amount) as spend, sum(conversions) as conversions')
            ->groupBy('reporting_date')
            ->get());

        return array_values(array_filter([
            ...$this->spendAlerts($days, $currency, 'Google Ads'),
            $this->conversionsStopped($days),
            ...$this->budgetAlerts($asset, $days, 'Google Ads'),
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function metaAds(DigitalAsset $asset): array
    {
        $binding = $this->metaBindings->resolve((string) $asset->id);
        if (! $binding->isReal() || ! Schema::hasTable('meta_account_daily')) {
            return [];
        }
        $days = $this->dailySeries(DB::table('meta_account_daily')
            ->where('digital_asset_id', $asset->id)
            ->where('account_id', (string) $binding->accountId)
            ->where('reporting_date', '>=', now()->subDays(40)->toDateString())
            ->selectRaw('reporting_date, sum(spend) as spend')
            ->groupBy('reporting_date')
            ->get());

        return [...$this->spendAlerts($days, (string) ($binding->currency ?? ''), 'Meta'), ...$this->budgetAlerts($asset, $days, 'Meta')];
    }

    /**
     * Budget watch: turns the latest ad_budget_status (checked every two hours) into "budget ran out" alerts.
     * A state older than six hours is ignored, so a broken check never keeps an old alert open.
     *
     * @param  array<string, array{spend: float, conversions: float}>  $days
     * @return list<array<string, mixed>>
     */
    private function budgetAlerts(DigitalAsset $asset, array $days, string $channel): array
    {
        if (! Schema::hasTable('ad_budget_status')) {
            return [];
        }
        $row = DB::table('ad_budget_status')->where('digital_asset_id', $asset->id)->first();
        if ($row === null || $row->checked_at === null || CarbonImmutable::parse((string) $row->checked_at)->lt(now()->subHours(6))) {
            return [];
        }
        $state = json_decode((string) $row->data, true);
        if (! is_array($state)) {
            return [];
        }
        $cfg = (array) config('moxdop-alerts.budget');
        $currency = (string) ($state['currency'] ?? '');
        $money = fn (float $value): string => number_format($value, 0, ',', '.').($currency !== '' ? ' '.$currency : '');
        $recent = array_slice($days, -7, 7, true);
        $daily = $recent === [] ? 0.0 : array_sum(array_column($recent, 'spend')) / count($recent);
        $alerts = [];

        if (filled($state['blocked'] ?? null)) {
            $alerts[] = $this->alert('budget_account_blocked', 'critical', $channel.' hesabı reklam yayınlayamıyor',
                sprintf('%s. Reklamlar bu durumda yayınlanmaz; ödeme / hesap durumunu reklam panelinden kontrol edin.', (string) $state['blocked']),
                ['reason' => (string) $state['blocked']]);
        }

        $limit = is_array($state['limit'] ?? null) ? $state['limit'] : null;
        if ($limit !== null) {
            $left = (float) $limit['cap'] - (float) $limit['spent'];
            if ($left <= 0.0 || ($limit['ended'] ?? false)) {
                $alerts[] = $this->alert('budget_exhausted', 'critical', $channel.' bütçesi bitti',
                    ($limit['ended'] ?? false)
                        ? sprintf('Hesap bütçesinin bitiş tarihi geçti (%s). Yeni bütçe tanımlanmadan reklamlar yayınlanmaz.', substr((string) $limit['ends_at'], 0, 10))
                        : sprintf('Hesap harcama limiti (%s) doldu. Limit artırılmadan reklamlar yayınlanmaz.', $money((float) $limit['cap'])),
                    ['cap' => (float) $limit['cap'], 'spent' => (float) $limit['spent']]);
            } elseif ($daily > 0 && $left < $daily * (float) ($cfg['low_days'] ?? 3)) {
                $alerts[] = $this->alert('budget_low', 'high', $channel.' bütçesi bitmek üzere',
                    sprintf('Hesap harcama limitinden %s kaldı; günlük ortalama harcama %s (yaklaşık %.1f gün). Limiti artırın veya yeni bütçe tanımlayın.', $money($left), $money($daily), $left / $daily),
                    ['left' => round($left, 2), 'daily' => round($daily, 2)]);
            }
        }

        $balance = $state['balance'] ?? null;
        if ($balance !== null) {
            if ((float) $balance <= 0.0) {
                $alerts[] = $this->alert('budget_exhausted', 'critical', $channel.' ön ödemeli bakiye bitti',
                    'Ön ödemeli hesapta bakiye kalmadı. Bakiye yüklenmeden reklamlar yayınlanmaz.', ['balance' => (float) $balance]);
            } elseif ($daily > 0 && (float) $balance < $daily * (float) ($cfg['low_days'] ?? 3)) {
                $alerts[] = $this->alert('budget_low', 'high', $channel.' bakiyesi azaldı',
                    sprintf('Ön ödemeli bakiye %s; günlük ortalama harcama %s (yaklaşık %.1f gün). Bakiye yükleyin.', $money((float) $balance), $money($daily), (float) $balance / $daily),
                    ['balance' => (float) $balance, 'daily' => round($daily, 2)]);
            }
        }

        $hour = (int) ($state['local_hour'] ?? 0);
        if ((float) ($state['today_spend'] ?? 0) <= 0.0 && $hour >= (int) ($cfg['zero_spend_after_hour'] ?? 14)
            && $daily >= (float) config('moxdop-alerts.delivery_stopped.min_baseline') && ! filled($state['blocked'] ?? null)) {
            $alerts[] = $this->alert('budget_no_spend_today', 'critical', $channel.' bugün hiç harcama yapmadı',
                sprintf('Saat %02d:00 oldu, bugün hiç harcama yok; son 7 günün ortalaması %s/gün. Bakiye, ödeme yöntemi veya bütçe bitmiş olabilir.', $hour, $money($daily)),
                ['date' => (string) ($state['local_date'] ?? ''), 'daily' => round($daily, 2)]);
        }

        $capped = (array) ($state['capped_campaigns'] ?? []);
        if ($capped !== [] && $hour < (int) ($cfg['capped_before_hour'] ?? 20)) {
            $names = implode(', ', array_map(fn (array $c): string => (string) $c['name'], array_slice($capped, 0, 3)));
            $alerts[] = $this->alert('budget_campaign_capped', 'high', $channel.' kampanya bütçesi gün bitmeden doldu',
                sprintf('%d kampanyanın günlük bütçesi saat %02d:00 itibarıyla doldu (%s). Günün kalanında bu kampanyalar gösterilmez.', (int) ($state['capped_count'] ?? count($capped)), $hour, $names),
                ['campaigns' => $capped]);
        }

        $disapproved = (int) ($state['disapproved_count'] ?? 0);
        if ($disapproved > 0) {
            $names = implode(', ', array_map(fn (array $ad): string => (string) $ad['name'], array_slice((array) ($state['disapproved'] ?? []), 0, 3)));
            $alerts[] = $this->alert('ads_disapproved', 'high', $channel.' reklamları reddedildi / sorunlu',
                sprintf('%d reklam reddedilmiş veya sorunlu (%s). Bu reklamlar yayınlanmıyor; reklam panelinden nedeni kontrol edin.', $disapproved, $names),
                ['ads' => (array) ($state['disapproved'] ?? [])]);
        }

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function website(DigitalAsset $asset): array
    {
        if (! Schema::hasTable('gsc_property_daily')) {
            return [];
        }
        $latest = $this->seoInputs->scopeGsc(DB::table('gsc_property_daily'), $asset, 'gsc_property_daily')->max('reporting_date');
        if ($latest === null) {
            return [];
        }
        $end = CarbonImmutable::parse((string) $latest);
        $sum = fn (CarbonImmutable $from, CarbonImmutable $to): int => (int) $this->seoInputs
            ->scopeGsc(DB::table('gsc_property_daily'), $asset, 'gsc_property_daily')
            ->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])
            ->sum('clicks');
        $current = $sum($end->subDays(6), $end);
        $previous = $sum($end->subDays(13), $end->subDays(7));
        $cfg = (array) config('moxdop-alerts.search_traffic_drop');
        if ($previous < (int) $cfg['min_previous_clicks']) {
            return [];
        }
        $drop = (int) round((1 - $current / $previous) * 100);
        if ($drop < (int) $cfg['drop_pct']) {
            return [];
        }

        return [$this->alert('search_traffic_drop', $drop >= 60 ? 'high' : 'medium', 'Google arama tıklamaları düştü',
            sprintf('Son 7 günde %s tık, önceki 7 günde %s (−%%%d). Search Console sekmesinde düşen sayfaları kontrol edin.', number_format($current, 0, ',', '.'), number_format($previous, 0, ',', '.'), $drop),
            ['current' => $current, 'previous' => $previous, 'drop_pct' => $drop, 'end' => $end->toDateString()])];
    }

    /**
     * GA4: sessions or key events (conversions) of the last 7 days fell sharply against the 7 days before.
     *
     * @return list<array<string, mixed>>
     */
    private function ga4Drops(DigitalAsset $asset): array
    {
        if (! Schema::hasTable('ga4_property_daily')) {
            return [];
        }
        $latest = $this->seoInputs->scopeGa4(DB::table('ga4_property_daily'), $asset)->max('reporting_date');
        if ($latest === null) {
            return [];
        }
        $end = CarbonImmutable::parse((string) $latest);
        $sum = fn (string $column, CarbonImmutable $from, CarbonImmutable $to): float => (float) $this->seoInputs
            ->scopeGa4(DB::table('ga4_property_daily'), $asset)
            ->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])->sum($column);
        $cfg = (array) config('moxdop-alerts.ga4_drop');
        $alerts = [];
        foreach ([['sessions', 'min_previous_sessions', 'ga4_sessions_drop', 'Site ziyaretleri düştü', 'oturum'], ['keyEvents', 'min_previous_key_events', 'ga4_conversions_drop', 'Site dönüşümleri düştü', 'dönüşüm']] as [$column, $minKey, $kind, $title, $unit]) {
            if (! Schema::hasColumn('ga4_property_daily', $column)) {
                continue;
            }
            $current = $sum($column, $end->subDays(6), $end);
            $previous = $sum($column, $end->subDays(13), $end->subDays(7));
            if ($previous < (float) ($cfg[$minKey] ?? 50)) {
                continue;
            }
            $drop = (int) round((1 - $current / $previous) * 100);
            if ($drop < (int) ($cfg['drop_pct'] ?? 35)) {
                continue;
            }
            $alerts[] = $this->alert($kind, $drop >= 60 ? 'high' : 'medium', $title,
                sprintf('Son 7 günde %s %s, önceki 7 günde %s (−%%%d). Google Analytics sekmesinde kanalları ve giriş sayfalarını kontrol edin.', number_format($current, 0, ',', '.'), $unit, number_format($previous, 0, ',', '.'), $drop),
                ['current' => $current, 'previous' => $previous, 'drop_pct' => $drop, 'end' => $end->toDateString()]);
        }

        return $alerts;
    }

    /**
     * Paired WordPress connector: plugin older than the current release (drafts / delta updates may need it).
     *
     * @return list<array<string, mixed>>
     */
    private function wordpressConnector(DigitalAsset $asset): array
    {
        if (! Schema::hasTable('website_connector_delivery')) {
            return [];
        }
        $installed = DB::table('website_connector_delivery as d')->join('core_connections as c', 'c.id', '=', 'd.connection_id')
            ->where('c.digital_asset_id', $asset->id)->where('c.type', 'wordpress_connector')->where('c.enabled', true)
            ->value('d.plugin_version');
        $current = (string) config('moxdop-wordpress.connector_version', '');
        if ($installed === null || ! SystemHealthReader::isOutdated((string) $installed, $current)) {
            return [];
        }

        return [$this->alert('wordpress_plugin_outdated', 'low', 'WordPress eklentisi güncel değil',
            sprintf('Sitede MoxDOP eklentisi %s kurulu; güncel sürüm %s. Entegrasyonlar › Site bağlayıcıları sayfasından yeni sürümü indirip yükleyin.', $installed, $current),
            ['installed' => (string) $installed, 'current' => $current])];
    }

    /**
     * Renewals of this website (domain / SSL / hosting / other) that expire within 30 days or have expired;
     * auto-renewing ones only from 7 days.
     *
     * @return list<array<string, mixed>>
     */
    private function renewals(DigitalAsset $asset): array
    {
        if (! Schema::hasTable('asset_renewals')) {
            return [];
        }
        $alerts = [];
        foreach (AssetRenewal::query()->where('digital_asset_id', $asset->id)->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(30))->get() as $renewal) {
            $days = (int) $renewal->daysLeft();
            if ($renewal->auto_renew && $days > 7) {
                continue;
            }
            $label = AssetRenewal::KINDS[$renewal->kind] ?? 'Yenileme';
            $alerts[] = $this->alert('renewal_due_'.$renewal->kind, $days < 0 ? 'critical' : ($days <= 7 ? 'high' : 'medium'),
                $label.' yenilemesi yaklaşıyor',
                sprintf('%s: %s (%s).%s', $renewal->label, $days < 0 ? 'süresi '.abs($days).' gün önce doldu' : $days.' gün kaldı', $renewal->expires_on?->format('d.m.Y'),
                    $renewal->charge_amount !== null ? ' Tahsilat: '.(AssetRenewal::COLLECTION[$renewal->collection_status] ?? $renewal->collection_status).'.' : ''),
                ['renewal_id' => $renewal->id, 'days_left' => $days]);
        }

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function businessProfile(DigitalAsset $asset): array
    {
        $resourceId = CoreAssetBinding::query()->where('digital_asset_id', $asset->id)->where('capability', 'google_business_profile')->where('status', CoreAssetBinding::STATUS_ACTIVE)->value('external_resource_id');
        if ($resourceId === null) {
            return [];
        }
        $cfg = (array) config('moxdop-alerts.bad_review');
        $stars = array_slice(['ONE', 'TWO', 'THREE', 'FOUR', 'FIVE'], 0, max(1, (int) $cfg['max_stars']));
        $count = DB::table('gbp_reviews')
            ->where('external_resource_id', $resourceId)
            ->whereIn('star_rating', $stars)
            ->whereNull('review_reply')
            ->where('create_time', '>=', now()->subDays((int) $cfg['days']))
            ->count();
        if ($count === 0) {
            return [];
        }

        return [$this->alert('bad_review_unanswered', 'high', 'Yanıtsız düşük puanlı yorum',
            sprintf('Son %d günde %d adet %d yıldız veya altı yorum yanıt bekliyor. Yorumlar sekmesinden görün, yanıtı İşletme Profili’nden verin.', (int) $cfg['days'], $count, (int) $cfg['max_stars']),
            ['count' => $count])];
    }

    /**
     * @param  array<string, array{spend: float, conversions: float}>  $days  keyed by Y-m-d, ascending
     * @return list<array<string, mixed>>
     */
    private function spendAlerts(array $days, string $currency, string $channel): array
    {
        if (count($days) < 8) {
            return [];
        }
        $dates = array_keys($days);
        $last = end($dates);
        if ($last < now()->subDays(3)->toDateString()) {
            return []; // old data: the stale-data alert covers it
        }
        $baselineDays = array_slice($days, -8, 7, true);
        $baseline = array_sum(array_column($baselineDays, 'spend')) / 7;
        $yesterday = (float) $days[$last]['spend'];
        $money = fn (float $value): string => number_format($value, 0, ',', '.').($currency !== '' ? ' '.$currency : '');
        $spike = (array) config('moxdop-alerts.spend_spike');
        $alerts = [];
        if ($baseline >= (float) $spike['min_baseline'] && $yesterday >= $baseline * (float) $spike['ratio']) {
            $alerts[] = $this->alert('spend_spike', 'high', $channel.' harcaması sıçradı',
                sprintf('%s günü harcama %s; önceki 7 günün ortalaması %s (%.1f kat). Bütçe ve teklif değişikliklerini kontrol edin.', $last, $money($yesterday), $money($baseline), $yesterday / max($baseline, 0.01)),
                ['date' => $last, 'spend' => $yesterday, 'baseline' => round($baseline, 2)]);
        }
        if ($baseline >= (float) config('moxdop-alerts.delivery_stopped.min_baseline') && $yesterday <= 0.0) {
            $alerts[] = $this->alert('delivery_stopped', 'critical', $channel.' reklamları harcama yapmadı',
                sprintf('%s günü hiç harcama yok; önceki 7 günün ortalaması %s. Ödeme, onay veya kampanya durumu sorunu olabilir.', $last, $money($baseline)),
                ['date' => $last, 'baseline' => round($baseline, 2)]);
        }

        return $alerts;
    }

    /** @param  array<string, array{spend: float, conversions: float}>  $days */
    private function conversionsStopped(array $days): ?array
    {
        $cfg = (array) config('moxdop-alerts.conversions_stopped');
        $window = (int) $cfg['days'];
        if (count($days) < $window + 14) {
            return null;
        }
        $recent = array_slice($days, -$window, $window, true);
        $prior = array_slice($days, -($window + 14), 14, true);
        $priorAvg = array_sum(array_column($prior, 'conversions')) / 14;
        $recentSpend = array_sum(array_column($recent, 'spend'));
        $recentConversions = array_sum(array_column($recent, 'conversions'));
        if ($priorAvg < (float) $cfg['min_daily_conversions'] || $recentSpend <= 0.0 || $recentConversions > 0.0) {
            return null;
        }

        return $this->alert('conversions_stopped', 'critical', 'Dönüşüm gelmiyor',
            sprintf('Son %d günde harcama var ama hiç dönüşüm yok; önceki 14 günde günde ortalama %s dönüşüm vardı. Dönüşüm etiketi / form bozulmuş olabilir.', $window, number_format($priorAvg, 1, ',', '.')),
            ['prior_daily' => round($priorAvg, 2), 'days' => $window]);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, array{spend: float, conversions: float}>
     */
    private function dailySeries(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[substr((string) $row->reporting_date, 0, 10)] = ['spend' => (float) ($row->spend ?? 0), 'conversions' => (float) ($row->conversions ?? 0)];
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{kind: string, severity: string, title: string, message: string, data: array<string, mixed>}
     */
    private function alert(string $kind, string $severity, string $title, string $message, array $data = []): array
    {
        return compact('kind', 'severity', 'title', 'message', 'data');
    }

    /**
     * @param  list<array{kind: string, severity: string, title: string, message: string, data: array<string, mixed>}>  $detected
     * @return array{open: int, new: int, resolved: int}
     */
    private function persist(DigitalAsset $asset, array $detected): array
    {
        $new = 0;
        $keys = [];
        foreach ($detected as $alert) {
            $key = hash('sha256', $alert['kind']);
            $keys[] = $key;
            $row = AssetAlert::query()->firstOrNew(['digital_asset_id' => $asset->id, 'alert_key' => $key]);
            $isNew = ! $row->exists || $row->resolved_at !== null;
            if ($isNew) {
                $row->first_detected_at = now();
                $row->resolved_at = null;
                $row->snoozed_until = null;
                $row->snoozed_by = null;
                $new++;
            }
            $row->fill([
                'brand_id' => $asset->brand_id,
                'kind' => $alert['kind'],
                'severity' => $alert['severity'],
                'title' => $alert['title'],
                'message' => $alert['message'],
                'data' => $alert['data'],
                'last_detected_at' => now(),
            ])->save();
            // Faz 6: a newly opened high / critical alert also goes to the phone (once per alert opening).
            if ($isNew && in_array($alert['severity'], ['high', 'critical'], true) && $alert['kind'] !== 'site_down') {
                try {
                    app(PushNotifier::class)->send('alert:'.$asset->id.':'.$alert['kind'].':'.$row->first_detected_at?->format('Ymd'),
                        $alert['title'].' — '.($asset->name ?? $asset->domain), $alert['message'], $alert['severity'], null, 24);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
        $resolved = AssetAlert::query()->open()
            ->where('digital_asset_id', $asset->id)
            ->when($keys !== [], fn ($query) => $query->whereNotIn('alert_key', $keys))
            ->update(['resolved_at' => now()]);

        return ['open' => count($keys), 'new' => $new, 'resolved' => $resolved];
    }
}
