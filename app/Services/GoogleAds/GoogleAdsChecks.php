<?php

namespace App\Services\GoogleAds;

use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 5 Google Ads system checks (no AI, at most 10): deterministic, from the collected data of the bound account.
 * Each check is `pass`, `fail` (becomes a suggestion with its evidence) or `nodata` ("veri yok" — never a guess).
 * Performance judgements need enough data; no check ever proposes pausing or closing anything.
 */
final class GoogleAdsChecks
{
    public const array LABELS = [
        'conversion_tracking' => 'Dönüşüm izleme',
        'conversion_goals' => 'Birincil / ikincil hedefler',
        'budget' => 'Bütçe kullanımı',
        'targeting' => 'Hedef bölge ve dil',
        'landing' => 'Açılış sayfaları',
        'policy' => 'Reklam onay durumu',
        'negative_conflict' => 'Negatif çakışması',
        'service_campaign' => 'Hizmet ↔ kampanya',
        'anomaly' => 'Maliyet / dönüşüm sapması',
    ];

    /** Conversion categories that inflate a bidding goal when they are primary. */
    private const array WEAK_CATEGORIES = ['PAGE_VIEW', 'ENGAGEMENT'];

    /** Conversion categories that are real leads / sales. */
    private const array LEAD_CATEGORIES = ['SUBMIT_LEAD_FORM', 'PHONE_CALL_LEAD', 'CONTACT', 'BOOK_APPOINTMENT', 'REQUEST_QUOTE', 'PURCHASE', 'QUALIFIED_LEAD', 'CONVERTED_LEAD', 'IMPORTED_LEAD', 'GET_DIRECTIONS'];

    /** Enough data for a performance judgement. */
    public const int MIN_CLICKS = 50;

    public const int MIN_CONVERSIONS = 10;

    public function __construct(
        private readonly GoogleAdsAdvisorInputCollector $collector,
        private readonly GoogleAdsScreen $screen,
    ) {}

    /**
     * @return list<array{id: string, label: string, state: string, reason: string, priority: int, evidence: list<array<string, mixed>>, todo: string}>
     */
    public function run(DigitalAsset $asset): array
    {
        $pack = $this->collector->collect($asset);
        if (! ($pack['bound'] ?? false)) {
            return [];
        }
        $asset->loadMissing('brand');

        return [
            $this->conversionTracking($pack),
            $this->conversionGoals($pack),
            $this->budget($pack),
            $this->targeting($asset, $pack),
            $this->landing($asset, $pack),
            $this->policy($asset, $pack),
            $this->negativeConflicts($pack),
            $this->serviceCampaign($asset, $pack),
            $this->anomaly($pack),
        ];
    }

    /**
     * Whether a negative keyword blocks a query (Google rules without close variants): EXACT = same words, PHRASE =
     * the words in order, BROAD = all words in any order.
     */
    public static function blocks(string $negative, string $matchType, string $query): bool
    {
        $neg = array_values(array_filter(explode(' ', SeoText::fold($negative))));
        $words = array_values(array_filter(explode(' ', SeoText::fold($query))));
        if ($neg === [] || $words === []) {
            return false;
        }

        return match (strtoupper($matchType)) {
            'EXACT' => $neg === $words,
            'BROAD' => array_diff($neg, $words) === [],
            default => str_contains(' '.implode(' ', $words).' ', ' '.implode(' ', $neg).' '),
        };
    }

    /** @param  array<string, mixed>  $pack */
    private function conversionTracking(array $pack): array
    {
        $actions = $pack['conversion_actions'] ?? ['available' => false, 'items' => []];
        if (! $actions['available']) {
            return $this->result('conversion_tracking', 'nodata', 'Dönüşüm işlemi verisi yok.');
        }
        $primary = array_values(array_filter($actions['items'], fn (array $a): bool => $a['primary'] && $a['status'] === 'ENABLED'));
        if ($primary === []) {
            return $this->result('conversion_tracking', 'fail', 'Etkin birincil dönüşüm işlemi yok; teklif stratejisi neyi artıracağını bilmiyor.', 1,
                array_map(fn (array $a): array => ['dönüşüm' => $a['name'], 'durum' => $a['status'] ?? 'veri yok', 'birincil' => $a['primary'] ? 'evet' : 'hayır'], array_slice($actions['items'], 0, 10)),
                'Asıl talep işlemini (form, arama, randevu) birincil yapın.');
        }
        $clicks = (int) ($pack['account']['clicks'] ?? 0);
        if (($actions['daily_available'] ?? false) && $clicks >= 100) {
            $silent = array_values(array_filter($primary, fn (array $a): bool => (float) ($a['conversions'] ?? 0) <= 0));
            if (count($silent) === count($primary)) {
                return $this->result('conversion_tracking', 'fail', '30 günde '.$clicks.' tık var, birincil dönüşüm 0: izleme çalışmıyor olabilir.', 1,
                    array_map(fn (array $a): array => ['dönüşüm' => $a['name'], 'son_30_gün' => 0], $silent), 'Etiketi ve dönüşüm işleminin durumunu Google Ads’te test edin.');
            }
        }

        return $this->result('conversion_tracking', 'pass', count($primary).' birincil dönüşüm işlemi etkin.');
    }

    /** @param  array<string, mixed>  $pack */
    private function conversionGoals(array $pack): array
    {
        $actions = $pack['conversion_actions'] ?? ['available' => false, 'items' => []];
        if (! $actions['available']) {
            return $this->result('conversion_goals', 'nodata', 'Dönüşüm işlemi verisi yok.');
        }
        $enabled = array_filter($actions['items'], fn (array $a): bool => $a['status'] === 'ENABLED');
        $weakPrimary = array_values(array_filter($enabled, fn (array $a): bool => $a['primary'] && in_array(strtoupper((string) $a['category']), self::WEAK_CATEGORIES, true)));
        $leadPrimary = array_filter($enabled, fn (array $a): bool => $a['primary'] && in_array(strtoupper((string) $a['category']), self::LEAD_CATEGORIES, true));
        $leadSecondary = array_values(array_filter($enabled, fn (array $a): bool => ! $a['primary'] && in_array(strtoupper((string) $a['category']), self::LEAD_CATEGORIES, true)));
        $evidence = [];
        foreach ($weakPrimary as $a) {
            $evidence[] = ['dönüşüm' => $a['name'], 'kategori' => $a['category'], 'şu an' => 'birincil', 'olmalı' => 'ikincil'];
        }
        if ($leadPrimary === []) {
            foreach ($leadSecondary as $a) {
                $evidence[] = ['dönüşüm' => $a['name'], 'kategori' => $a['category'], 'şu an' => 'ikincil', 'olmalı' => 'birincil'];
            }
        }
        if ($evidence === []) {
            return $this->result('conversion_goals', 'pass', 'Birincil hedefler talep işlemleri.');
        }

        return $this->result('conversion_goals', 'fail', $weakPrimary !== []
            ? $weakPrimary[0]['name'].' birincil hedef; sayfa görüntüleme / etkileşim teklifi yanıltır.'
            : $leadSecondary[0]['name'].' talep işlemi ama ikincil; birincil talep hedefi yok.', 2, $evidence, 'Talep işlemlerini birincil, ötekileri ikincil yapın.');
    }

    /** @param  array<string, mixed>  $pack */
    private function budget(array $pack): array
    {
        $campaigns = array_filter($pack['campaigns'] ?? [], fn (array $c): bool => ($c['status'] ?? null) === 'ENABLED');
        if ($campaigns === []) {
            return $this->result('budget', 'nodata', 'Etkin kampanya verisi yok.');
        }
        $end = $pack['period']['end'];
        $evidence = [];
        foreach ($campaigns as $id => $c) {
            $daily = $pack['campaign_daily'][$id] ?? [];
            $recent = $prior = 0.0;
            foreach ($daily as $date => $m) {
                $age = (int) ((strtotime($end) - strtotime((string) $date)) / 86400);
                if ($age < 7) {
                    $recent += $m['cost'];
                } elseif ($age < 28) {
                    $prior += $m['cost'];
                }
            }
            $recentAvg = $recent / 7;
            $priorAvg = $prior / 21;
            if (($c['lost_is_budget'] ?? null) !== null && $c['lost_is_budget'] >= 0.2 && $c['conversions'] > 0) {
                $evidence[] = ['kampanya' => $c['name'], 'bütçe_kaybı_%' => round($c['lost_is_budget'] * 100), 'günlük_bütçe' => $c['budget_amount'], 'dönüşüm_30g' => round($c['conversions'], 1)];
            } elseif ($priorAvg >= 10 && abs($recentAvg - $priorAvg) / $priorAvg >= 0.6) {
                $evidence[] = ['kampanya' => $c['name'], 'son_7g_günlük' => round($recentAvg, 2), 'önceki_21g_günlük' => round($priorAvg, 2), 'değişim_%' => (int) round(($recentAvg - $priorAvg) / $priorAvg * 100)];
            }
        }
        if ($evidence === []) {
            return $this->result('budget', 'pass', 'Bütçe kullanımı olağan.');
        }
        $first = $evidence[0];
        $reason = isset($first['bütçe_kaybı_%'])
            ? $first['kampanya'].': gösterimlerin %'.$first['bütçe_kaybı_%'].' kadarı bütçe yetmediği için kaçtı.'
            : $first['kampanya'].': günlük harcama %'.$first['değişim_%'].' değişti (son 7 gün / önceki 21 gün).';

        return $this->result('budget', 'fail', $reason, 2, $evidence, 'Bütçeyi ya da değişikliğin nedenini kontrol edin.');
    }

    /** @param  array<string, mixed>  $pack */
    private function targeting(DigitalAsset $asset, array $pack): array
    {
        $areas = BrandServiceArea::query()->where('brand_id', (int) $asset->brand_id)->where('status', 'active')->get();
        $regions = $pack['segments']['region'] ?? [];
        if ($areas->isEmpty() || $regions === []) {
            return $this->result('targeting', 'nodata', $areas->isEmpty() ? 'Markanın hizmet bölgesi yok.' : 'Bölge verisi yok.');
        }
        $needles = $areas->flatMap(fn (BrandServiceArea $a): array => array_filter([SeoText::fold((string) $a->city_name), SeoText::fold((string) $a->district_name), SeoText::fold((string) $a->name)]))
            ->filter()->unique()->values()->all();
        $total = array_sum(array_column($regions, 'cost'));
        $outside = array_values(array_filter($regions, function (array $r) use ($needles): bool {
            $label = SeoText::fold((string) $r['label']);
            foreach ($needles as $needle) {
                if (str_contains(' '.$label.' ', ' '.$needle.' ') || str_contains(' '.$needle.' ', ' '.$label.' ')) {
                    return false;
                }
            }

            return true;
        }));
        $outsideCost = array_sum(array_column($outside, 'cost'));
        if ($total <= 0 || $outsideCost / $total < 0.2) {
            return $this->result('targeting', 'pass', 'Harcamanın çoğu hizmet bölgelerinde.');
        }
        usort($outside, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        $share = (int) round($outsideCost / $total * 100);
        $evidence = array_map(fn (array $r): array => ['bölge' => $r['label'], 'maliyet' => round($r['cost'], 2), 'dönüşüm' => round($r['conversions'], 1)], array_slice($outside, 0, 10));
        $evidence[] = ['dil_hedeflemesi' => 'veri yok', 'marka_dilleri' => implode(', ', (array) ($asset->brand?->languages ?? [])) ?: 'veri yok'];

        return $this->result('targeting', 'fail', 'Harcamanın %'.$share.' kadarı hizmet bölgeleri dışında.', 2, $evidence, 'Konum hedeflemesini “bulunan kişiler” ve hizmet bölgeleriyle sınırlayın.');
    }

    /** @param  array<string, mixed>  $pack */
    private function landing(DigitalAsset $asset, array $pack): array
    {
        $landing = array_filter($pack['landing_pages'] ?? [], fn (array $l): bool => $l['cost'] > 0);
        $sites = DigitalAsset::query()->where('brand_id', (int) $asset->brand_id)->where('type', 'website')->get(['id', 'domain', 'primary_url']);
        if ($landing === [] || $sites->isEmpty()) {
            return $this->result('landing', 'nodata', $sites->isEmpty() ? 'Markanın web sitesi yok.' : 'Açılış sayfası verisi yok.');
        }
        $hosts = $sites->map(fn (DigitalAsset $s): string => self::host((string) ($s->domain ?: $s->primary_url)))->filter()->unique()->all();
        $pages = Page::query()->whereIn('website_asset_id', $sites->pluck('id'))->pluck('url')->map(fn ($u): string => self::normalizeUrl((string) $u))->flip()->all();
        $evidence = [];
        foreach ($landing as $url => $l) {
            $host = self::host($url);
            $problem = ! in_array($host, $hosts, true) ? 'markanın sitesi değil' : ($pages !== [] && ! isset($pages[self::normalizeUrl($url)]) ? 'sitede bulunamadı' : null);
            if ($problem !== null) {
                $evidence[] = ['url' => mb_substr($url, 0, 200), 'sorun' => $problem, 'maliyet' => round($l['cost'], 2), 'tık' => $l['clicks']];
            }
        }
        if ($evidence === []) {
            return $this->result('landing', 'pass', 'Açılış sayfaları markanın sitesinde.');
        }
        usort($evidence, fn (array $a, array $b): int => $b['maliyet'] <=> $a['maliyet']);

        return $this->result('landing', 'fail', count($evidence).' açılış sayfası '.$evidence[0]['sorun'].' ('.$evidence[0]['url'].').', 2, array_slice($evidence, 0, 20), 'Reklamların son URL’sini sitedeki doğru sayfaya çevirin.');
    }

    /** @param  array<string, mixed>  $pack */
    private function policy(DigitalAsset $asset, array $pack): array
    {
        $ctx = $this->screen->context($asset);
        if ($ctx === null || ! Schema::hasTable('google_ads_ad_daily')) {
            return $this->result('policy', 'nodata', 'Reklam onay verisi yok.');
        }
        $latest = [];
        $ctx['scope']->professional('google_ads_ad_daily')->whereBetween('reporting_date', [$pack['period']['start'], $pack['period']['end']])
            ->orderBy('reporting_date')->get(['ad_id', 'ad_group_id', 'metadata'])
            ->each(function (object $row) use (&$latest): void {
                $latest[(string) $row->ad_id] = GoogleAdsAdvisorInputCollector::decode($row->metadata) + ['_ad_group_id' => (string) $row->ad_group_id];
            });
        $withStatus = array_filter($latest, fn (array $m): bool => filled($m['approval_status'] ?? null));
        if ($withStatus === []) {
            return $this->result('policy', 'nodata', 'Reklam onay verisi yok.');
        }
        $evidence = [];
        foreach ($withStatus as $adId => $m) {
            $status = strtoupper((string) $m['approval_status']);
            if (in_array($status, ['DISAPPROVED', 'APPROVED_LIMITED', 'AREA_OF_INTEREST_ONLY'], true) && strtoupper((string) ($m['status'] ?? 'ENABLED')) === 'ENABLED') {
                $evidence[] = ['reklam' => $adId, 'reklam_grubu' => (string) ($m['ad_group_name'] ?? $m['_ad_group_id']), 'onay' => $status === 'DISAPPROVED' ? 'Reddedildi' : 'Sınırlı'];
            }
        }
        if ($evidence === []) {
            return $this->result('policy', 'pass', count($withStatus).' reklamın onayı sorunsuz.');
        }
        $disapproved = count(array_filter($evidence, fn (array $e): bool => $e['onay'] === 'Reddedildi'));

        return $this->result('policy', 'fail', $disapproved > 0 ? $disapproved.' etkin reklam reddedildi.' : count($evidence).' etkin reklamın onayı sınırlı.',
            $disapproved > 0 ? 1 : 2, array_slice($evidence, 0, 20), 'Google Ads’te politika nedenini okuyup metni düzeltin.');
    }

    /** @param  array<string, mixed>  $pack */
    private function negativeConflicts(array $pack): array
    {
        $keywords = array_filter($pack['keywords'] ?? [], fn (array $k): bool => ($k['status'] ?? null) === 'ENABLED' && $k['text'] !== '');
        if ($keywords === []) {
            return $this->result('negative_conflict', 'nodata', 'Anahtar kelime verisi yok.');
        }
        $evidence = [];
        foreach ($pack['negatives'] ?? [] as $negative) {
            foreach ($keywords as $k) {
                $applies = match ($negative['level']) {
                    'campaign' => $negative['campaign_id'] !== null && $negative['campaign_id'] === $k['campaign_id'],
                    'ad_group' => $negative['ad_group_id'] === $k['ad_group_id'],
                    default => true,
                };
                if ($applies && self::blocks($negative['text'], (string) ($negative['match_type'] ?? 'PHRASE'), $k['text'])) {
                    $evidence[] = ['negatif' => $negative['text'], 'eşleme' => $negative['match_type'], 'kapsam' => ['campaign' => 'kampanya', 'ad_group' => 'reklam grubu'][$negative['level']] ?? 'paylaşılan liste',
                        'engellenen_anahtar_kelime' => $k['text'], 'maliyet_30g' => round($k['cost'], 2)];
                }
            }
        }
        if ($evidence === []) {
            return $this->result('negative_conflict', 'pass', 'Negatifler etkin anahtar kelimeleri engellemiyor.');
        }

        return $this->result('negative_conflict', 'fail', '«'.$evidence[0]['negatif'].'» negatifi «'.$evidence[0]['engellenen_anahtar_kelime'].'» anahtar kelimesini engelliyor.', 1,
            array_slice($evidence, 0, 20), 'Negatifi kaldırın ya da eşleme türünü daraltın.');
    }

    /** @param  array<string, mixed>  $pack */
    private function serviceCampaign(DigitalAsset $asset, array $pack): array
    {
        $main = BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', (int) $asset->brand_id)->where('status', 'active')->where('priority', 'main')->get();
        $keywords = array_filter($pack['keywords'] ?? [], fn (array $k): bool => ($k['status'] ?? null) === 'ENABLED' && $k['text'] !== '');
        $campaigns = array_filter($pack['campaigns'] ?? [], fn (array $c): bool => ($c['status'] ?? null) === 'ENABLED');
        if ($main->isEmpty() || ($keywords === [] && $campaigns === [])) {
            return $this->result('service_campaign', 'nodata', $main->isEmpty() ? 'Markanın ana hizmeti yok.' : 'Kampanya verisi yok.');
        }
        $services = $this->screen->services($asset, array_values(array_unique(array_column($keywords, 'text'))));
        $covered = array_flip(array_map(fn (string $s): string => SeoText::fold($s), array_values($services)));
        $names = SeoText::fold(implode(' | ', array_merge(array_column($campaigns, 'name'), array_values($pack['ads']['ad_groups'] ?? []))));
        $missing = [];
        foreach ($main as $offering) {
            $name = $offering->displayName();
            if (! isset($covered[SeoText::fold($name)]) && ! SeoText::containsPhrase($names, $name)) {
                $missing[] = ['ana_hizmet' => $name, 'kampanya' => 'yok'];
            }
        }
        $offeringNames = $main->map(fn (BrandOffering $o): string => SeoText::fold($o->displayName()))->all();
        $unmatched = [];
        foreach ($campaigns as $id => $c) {
            $campaignServices = [];
            foreach ($keywords as $k) {
                if ($k['campaign_id'] === (string) $id && isset($services[mb_strtolower($k['text'])])) {
                    $campaignServices[] = $services[mb_strtolower($k['text'])];
                }
            }
            if ($c['cost'] > 0 && $campaignServices === [] && ! collect($offeringNames)->contains(fn (string $n): bool => SeoText::containsPhrase((string) $c['name'], $n))) {
                $unmatched[] = ['kampanya' => $c['name'], 'hizmet' => 'eşleşmedi', 'maliyet_30g' => round($c['cost'], 2)];
            }
        }
        if ($missing === [] && $unmatched === []) {
            return $this->result('service_campaign', 'pass', 'Her ana hizmetin kampanyası var.');
        }
        $reason = $missing !== [] ? 'Ana hizmet «'.$missing[0]['ana_hizmet'].'» için kampanya ya da anahtar kelime yok.' : '«'.$unmatched[0]['kampanya'].'» kampanyası hiçbir hizmetle eşleşmiyor.';

        return $this->result('service_campaign', 'fail', $reason, $missing !== [] ? 1 : 3, array_merge($missing, $unmatched), 'Kampanya Stratejisi › Kampanya yapısı öner.');
    }

    /** @param  array<string, mixed>  $pack */
    private function anomaly(array $pack): array
    {
        $end = $pack['period']['end'];
        $evidence = [];
        $judged = 0;
        foreach ($pack['campaigns'] ?? [] as $id => $c) {
            $sums = ['recent' => ['cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0], 'base' => ['cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0]];
            foreach ($pack['campaign_daily'][$id] ?? [] as $date => $m) {
                $age = (int) ((strtotime($end) - strtotime((string) $date)) / 86400);
                $bucket = $age < 14 ? 'recent' : ($age < 42 ? 'base' : null);
                if ($bucket !== null) {
                    $sums[$bucket]['cost'] += $m['cost'];
                    $sums[$bucket]['clicks'] += $m['clicks'];
                    $sums[$bucket]['conversions'] += $m['conversions'];
                }
            }
            ['recent' => $recent, 'base' => $base] = $sums;
            if ($base['conversions'] < self::MIN_CONVERSIONS || $recent['clicks'] < self::MIN_CLICKS) {
                continue;
            }
            $judged++;
            $baseCpa = $base['cost'] / $base['conversions'];
            if ($recent['conversions'] <= 0 && $recent['cost'] >= 3 * $baseCpa) {
                $evidence[] = ['kampanya' => $c['name'], 'son_14g_maliyet' => round($recent['cost'], 2), 'son_14g_dönüşüm' => 0, 'önceki_edm' => round($baseCpa, 2)];
            } elseif ($recent['conversions'] > 0 && ($recent['cost'] / $recent['conversions']) >= 1.5 * $baseCpa) {
                $evidence[] = ['kampanya' => $c['name'], 'son_14g_edm' => round($recent['cost'] / $recent['conversions'], 2), 'önceki_28g_edm' => round($baseCpa, 2),
                    'değişim_%' => (int) round((($recent['cost'] / $recent['conversions']) / $baseCpa - 1) * 100)];
            }
        }
        if ($judged === 0) {
            return $this->result('anomaly', 'nodata', 'Yeterli veri yok (en az '.self::MIN_CONVERSIONS.' dönüşüm ve '.self::MIN_CLICKS.' tık gerekir).');
        }
        if ($evidence === []) {
            return $this->result('anomaly', 'pass', 'Dönüşüm başı maliyet olağan.');
        }
        $first = $evidence[0];
        $reason = isset($first['değişim_%'])
            ? $first['kampanya'].': dönüşüm başı maliyet %'.$first['değişim_%'].' arttı (son 14 gün).'
            : $first['kampanya'].': son 14 günde '.$first['son_14g_maliyet'].' harcama, 0 dönüşüm.';

        return $this->result('anomaly', 'fail', $reason, 1, $evidence, 'Son değişiklikleri, arama terimlerini ve dönüşüm izlemeyi kontrol edin.');
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @return array{id: string, label: string, state: string, reason: string, priority: int, evidence: list<array<string, mixed>>, todo: string}
     */
    private function result(string $id, string $state, string $reason, int $priority = 3, array $evidence = [], string $todo = ''): array
    {
        return ['id' => $id, 'label' => self::LABELS[$id], 'state' => $state, 'reason' => $reason, 'priority' => $priority, 'evidence' => $evidence, 'todo' => $todo];
    }

    public static function host(string $url): string
    {
        $url = trim($url);
        $host = (string) (parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST) ?? '');

        return preg_replace('/^www\./', '', mb_strtolower($host)) ?? '';
    }

    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return mb_strtolower(trim($url));
        }

        return self::host($url).'/'.trim(mb_strtolower(rawurldecode((string) ($parts['path'] ?? ''))), '/');
    }
}
