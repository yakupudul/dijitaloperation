<?php

namespace App\Services\Measurement;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\DigitalAsset;
use App\Services\Website\PublicDiscovery\StoredHtmlReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Website tracking health from stored data: measurement tags on the stored homepage against the bound GA4
 * property's measurement IDs, GA4 receiving no data while collection runs, counted website conversions
 * stopping, and a site with traffic but no counted conversion. Missing data never reads as zero: GA4 checks
 * need rows collected in the last days. Alerts belong to the brand's first website so they are not repeated.
 */
final class TrackingHealthChecker
{
    public function __construct(private readonly StoredHtmlReader $htmlReader) {}

    /**
     * @return list<array{kind: string, severity: string, title: string, message: string, data: array<string, mixed>}>
     */
    public function check(DigitalAsset $website): array
    {
        $alerts = [];
        $tags = $this->tags($website);
        $measurementIds = [];
        $brand = $website->brand;
        if ($brand !== null) {
            $scope = BrandMeasurementScope::for($brand);
            $measurementIds = $this->measurementIds($scope);
        }
        if ($tags !== null) {
            if ($tags['gtm'] === [] && $tags['ga4'] === []) {
                $alerts[] = $this->alert('tracking_tag_missing', 'high', 'Ana sayfada ölçüm etiketi yok',
                    'Kayıtlı ana sayfa HTML\'inde Google Tag Manager ya da GA4 (gtag) etiketi bulunamadı. Ziyaret ve dönüşümler ölçülmüyor olabilir; etiketin tüm sayfalarda yüklendiğini kontrol edin.',
                    ['tags' => $tags]);
            } elseif ($tags['gtm'] === [] && $measurementIds !== [] && array_intersect($tags['ga4'], $measurementIds) === []) {
                $alerts[] = $this->alert('tracking_ga4_id_mismatch', 'medium', 'Sitedeki GA4 kimliği bağlı mülkle eşleşmiyor',
                    sprintf('Sitede %s var; bağlı GA4 mülkünün ölçüm kimliği %s. Veriler başka bir mülke gidiyor olabilir.', implode(', ', $tags['ga4']), implode(', ', $measurementIds)),
                    ['site' => $tags['ga4'], 'bound' => $measurementIds]);
            }
            // Faz 14: Consent Mode. Only said when it is neither in the page nor a known CMP script is loaded; Tag
            // Manager may still set it, so the wording asks to check instead of claiming it is missing.
            $measured = $tags['gtm'] !== [] || $tags['ga4'] !== [] || $tags['google_ads'] !== [];
            if ($measured && ! ($tags['consent_mode'] ?? false) && ($tags['cmp'] ?? []) === []) {
                $alerts[] = $this->alert('consent_mode_not_seen', 'low', 'Onay modu (Consent Mode) görülmedi',
                    'Ana sayfada Google etiketi var ama sayfada Consent Mode varsayılanı ya da bilinen bir çerez onay aracı (Cookiebot, CookieYes, Complianz…) görülmedi. Tag Manager içinde kurulu olabilir; değilse çerez izni verilmeyen ziyaretlerin ölçümü ve reklam kitleleri etkilenir.',
                    ['tags' => array_intersect_key($tags, array_flip(['gtm', 'ga4', 'google_ads']))]);
            }
        }

        if ($brand === null || ! $this->isPrimaryWebsite($website, $brand) || ! Schema::hasTable('ga4_acquisition_channel_daily')) {
            return $alerts;
        }
        $cfg = (array) config('moxdop-alerts.tracking');
        $sessions = $this->dailySessions($scope);
        if ($sessions === null) {
            return $alerts;
        }
        [$days, $collectedRecently] = $sessions;
        $latest = array_key_last($days);
        $prior = array_filter($days, fn (string $date): bool => $date >= now()->subDays(17)->toDateString() && $date < now()->subDays(3)->toDateString(), ARRAY_FILTER_USE_KEY);
        $priorAvg = count($prior) > 0 ? array_sum($prior) / 14 : 0.0;
        if ($collectedRecently && $latest !== null && $latest < now()->subDays(3)->toDateString() && $priorAvg >= (float) $cfg['ga4_min_daily_sessions']) {
            $alerts[] = $this->alert('ga4_no_data', 'critical', 'GA4 veri almıyor',
                sprintf('GA4 verisi düzenli çekiliyor ama %s tarihinden beri hiç oturum yok; önceki günlerde günde ~%d oturum vardı. Site etiketi kaldırılmış ya da bozulmuş olabilir.', $latest, (int) round($priorAvg)),
                ['last_date' => $latest, 'prior_daily_sessions' => round($priorAvg, 1)]);

            return $alerts;
        }

        $counted = BrandConversionSource::query()->where('brand_id', $brand->id)->where('counts', true)->get();
        $monthSessions = array_sum(array_filter($days, fn (string $date): bool => $date >= now()->subDays(30)->toDateString(), ARRAY_FILTER_USE_KEY));
        if ($counted->isEmpty() && $monthSessions >= (int) $cfg['undefined_min_monthly_sessions']) {
            $alerts[] = $this->alert('conversions_not_defined', 'medium', 'Dönüşüm ölçülmüyor',
                sprintf('Son 30 günde %s oturum var ama toplamaya giren tek bir dönüşüm tanımı yok. GA4\'te form, arama ve WhatsApp tıklamalarını anahtar olay yapın; marka sayfasındaki Dönüşümler bölümünden işaretleyin.', number_format($monthSessions, 0, ',', '.')),
                ['sessions_30d' => $monthSessions]);
        }

        $ga4Events = $counted->where('source', BrandConversionSource::SOURCE_GA4)->pluck('source_key')->all();
        if ($ga4Events !== [] && $latest !== null) {
            $end = CarbonImmutable::parse($latest);
            $window = (int) $cfg['conversions_stopped_days'];
            $keyEvents = $scope->apply(DB::table('ga4_key_event_daily'))->whereIn('eventName', $ga4Events)
                ->whereBetween('reporting_date', [$end->subDays($window + 13)->toDateString(), $end->toDateString()])
                ->groupBy('reporting_date')->selectRaw('reporting_date, sum('.DB::getQueryGrammar()->wrap('keyEvents').') as n')->pluck('n', 'reporting_date')
                ->mapWithKeys(fn ($n, $date): array => [substr((string) $date, 0, 10) => (float) $n])->all();
            $recentFrom = $end->subDays($window - 1)->toDateString();
            $recent = array_sum(array_filter($keyEvents, fn (string $date): bool => $date >= $recentFrom, ARRAY_FILTER_USE_KEY));
            $before = array_sum(array_filter($keyEvents, fn (string $date): bool => $date < $recentFrom, ARRAY_FILTER_USE_KEY)) / 14;
            $recentSessions = array_sum(array_filter($days, fn (string $date): bool => $date >= $recentFrom, ARRAY_FILTER_USE_KEY));
            if ($recent <= 0.0 && $before >= (float) $cfg['conversions_min_daily'] && $recentSessions > 0) {
                $alerts[] = $this->alert('website_conversions_stopped', 'critical', 'Web sitesi dönüşümleri durdu',
                    sprintf('Son %d günde %s oturum var ama hiç form/arama/WhatsApp dönüşümü yok; önceki 14 günde günde ~%s vardı. Form, buton ya da etiket bozulmuş olabilir.', $window, number_format($recentSessions, 0, ',', '.'), number_format($before, 1, ',', '.')),
                    ['prior_daily' => round($before, 2), 'days' => $window]);
            }
        }

        // Faz 14: counted conversions dropped by half (last 7 days vs the 28 days before), not only to zero.
        if ($ga4Events !== [] && $latest !== null && ! in_array('website_conversions_stopped', array_column($alerts, 'kind'), true)) {
            $end = CarbonImmutable::parse($latest);
            $daily = $scope->apply(DB::table('ga4_key_event_daily'))->whereIn('eventName', $ga4Events)
                ->whereBetween('reporting_date', [$end->subDays(34)->toDateString(), $end->toDateString()])
                ->groupBy('reporting_date')->selectRaw('reporting_date, sum('.DB::getQueryGrammar()->wrap('keyEvents').') as n')->pluck('n', 'reporting_date')
                ->mapWithKeys(fn ($n, $date): array => [substr((string) $date, 0, 10) => (float) $n])->all();
            $recentFrom = $end->subDays(6)->toDateString();
            $recentAvg = array_sum(array_filter($daily, fn (string $date): bool => $date >= $recentFrom, ARRAY_FILTER_USE_KEY)) / 7;
            $beforeAvg = array_sum(array_filter($daily, fn (string $date): bool => $date < $recentFrom, ARRAY_FILTER_USE_KEY)) / 28;
            $share = (float) ($cfg['conversions_drop_share'] ?? 0.5);
            if ($beforeAvg >= (float) ($cfg['conversions_drop_min_daily'] ?? 2.0) && $recentAvg > 0 && $recentAvg <= $beforeAvg * (1 - $share)) {
                $alerts[] = $this->alert('website_conversions_dropped', 'high', 'Web sitesi dönüşümleri yarıdan fazla düştü',
                    sprintf('Son 7 günde günde ~%s dönüşüm var; önceki 28 günde ~%s idi (%%%d düşüş). Form, buton, etiket ya da trafik değişimini kontrol edin.',
                        number_format($recentAvg, 1, ',', '.'), number_format($beforeAvg, 1, ',', '.'), (int) round((1 - $recentAvg / $beforeAvg) * 100)),
                    ['recent_daily' => round($recentAvg, 2), 'prior_daily' => round($beforeAvg, 2)]);
            }
        }

        // Faz 14: the same website conversion counted twice (a GA4 key event and the Google Ads action importing it).
        $double = $counted->where('source', BrandConversionSource::SOURCE_GOOGLE_ADS)
            ->filter(fn (BrandConversionSource $row): bool => filled(data_get($row->metadata, 'ga4_event')) && in_array(data_get($row->metadata, 'ga4_event'), $ga4Events, true));
        if ($double->isNotEmpty()) {
            $alerts[] = $this->alert('conversions_double_counted', 'medium', 'Aynı dönüşüm iki kez sayılıyor',
                sprintf('%s hem GA4 anahtar olayı hem de onu içe aktaran Google Ads dönüşümü olarak toplama giriyor. Marka sayfasındaki Dönüşümler bölümünden birini "sayılmaz" yapın.', $double->pluck('label')->take(3)->implode(', ')),
                ['actions' => $double->pluck('source_key')->values()->all()]);
        }

        return $alerts;
    }

    /**
     * Tags found on the stored homepage, or null when no homepage HTML is stored.
     *
     * @return array{gtm: list<string>, ga4: list<string>, google_ads: list<string>, meta_pixel: list<string>, consent_mode: bool, cmp: list<string>, url: string, observed_at: string}|null
     */
    public function tags(DigitalAsset $website): ?array
    {
        if (! Schema::hasTable('website_html_snapshot')) {
            return null;
        }
        $snapshot = DB::table('website_html_snapshot')->where('digital_asset_id', $website->id)
            ->whereNotNull('raw_ingestion_object_id')->where(fn ($q) => $q->whereNull('status_code')->orWhere('status_code', 200))
            ->orderByDesc('observed_at')->orderByDesc('id')->limit(300)->get(['id', 'url', 'observed_at'])
            ->first(fn ($row): bool => in_array(rtrim((string) parse_url((string) $row->url, PHP_URL_PATH), '/'), [''], true));
        if ($snapshot === null) {
            return null;
        }
        try {
            $page = $this->htmlReader->read($website, (string) $snapshot->url, (int) $snapshot->id);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
        if ($page === null) {
            return null;
        }

        return TrackingTagDetector::detect($page['html']) + ['url' => (string) $snapshot->url, 'observed_at' => (string) $snapshot->observed_at];
    }

    /** @return list<string> */
    public function measurementIds(BrandMeasurementScope $scope): array
    {
        if (! Schema::hasTable('ga4_property_metadata')) {
            return [];
        }
        $ids = [];
        foreach ($scope->apply(DB::table('ga4_property_metadata'))->pluck('metadata') as $metadata) {
            foreach ((array) data_get(json_decode((string) $metadata, true), 'data_streams', []) as $stream) {
                $id = data_get($stream, 'webStreamData.measurementId');
                if (is_string($id) && $id !== '') {
                    $ids[strtoupper($id)] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Sessions per date over the last 45 days and whether collection wrote rows in the last 2 days.
     *
     * @return array{0: array<string, float>, 1: bool}|null null when the brand has no GA4 rows at all
     */
    private function dailySessions(BrandMeasurementScope $scope): ?array
    {
        $rows = $scope->apply(DB::table('ga4_acquisition_channel_daily'))->where('reporting_date', '>=', now()->subDays(45)->toDateString())
            ->groupBy('reporting_date')->selectRaw('reporting_date, sum(sessions) as sessions, max(last_collected_at) as collected')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $days = [];
        foreach ($rows as $row) {
            $days[substr((string) $row->reporting_date, 0, 10)] = (float) $row->sessions;
        }
        ksort($days);
        // A day without sessions has no rows, so "collection ran" also counts the property metadata refresh.
        $collected = collect([$rows->max('collected'), $scope->apply(DB::table('ga4_property_metadata'))->max('last_collected_at')])->filter()->max();

        return [$days, $collected !== null && CarbonImmutable::parse((string) $collected)->gte(now()->subDays(2))];
    }

    private function isPrimaryWebsite(DigitalAsset $website, Brand $brand): bool
    {
        return (int) $brand->digitalAssets()->where('type', 'website')->orderBy('id')->value('id') === (int) $website->id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{kind: string, severity: string, title: string, message: string, data: array<string, mixed>}
     */
    private function alert(string $kind, string $severity, string $title, string $message, array $data = []): array
    {
        return compact('kind', 'severity', 'title', 'message', 'data');
    }
}
