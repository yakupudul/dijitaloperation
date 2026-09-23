<?php

namespace App\Services\Advisor\MetaAds;

use App\Enums\AdvisorCategory;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\Advisor\Support\BuildsAdvisorItems;
use App\Services\Advisor\Support\ChangeImpact;

/**
 * Meta Ads advisor rules over the collector package (pure: no database, no AI).
 *
 * Thresholds are config, not folklore; every item names its numbers and source. Rules stay silent when
 * their data was not collected (e.g. no pixel list → no pixel item, no breakdowns → no placement item).
 */
final class MetaAdsAdvisorRuleEngine
{
    use BuildsAdvisorItems;

    /** @var array<string, mixed> */
    private array $cfg = [];

    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, silenced: list<string>, summary: array<string, mixed>}
     */
    public function evaluate(array $input): array
    {
        $this->cfg = (array) config('moxdop-advisor.meta_ads', []);
        if (! ($input['bound'] ?? false)) {
            return ['items' => [], 'silenced' => ['not_bound'], 'summary' => ['reason' => 'not_bound']];
        }
        $account = $input['account'];
        if (! $account['has_data']) {
            return ['items' => [], 'silenced' => ['no_campaign_data'], 'summary' => ['reason' => 'no_campaign_data']];
        }
        if ($account['spend'] < (float) ($this->cfg['min_account_spend'] ?? 300)) {
            return ['items' => [], 'silenced' => ['low_spend'], 'summary' => ['reason' => 'low_spend', 'cost' => round($account['spend'], 2)]];
        }

        $items = array_merge(
            $this->creativeFatigue($input),
            $this->audienceSaturation($input),
            $this->learningLimited($input),
            $this->spendWithoutResults($input),
            $this->pixelHealth($input),
            $this->deliveryOutliers($input),
            $this->landingPages($input),
            $this->changeImpact($input),
        );
        usort($items, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);
        $max = (int) ($this->cfg['max_open'] ?? 6);
        $kept = [];
        $silenced = [];
        foreach ($items as $item) {
            if (count($kept) < $max || $item['severity'] === 'critical') {
                $kept[] = $item;
            } else {
                $silenced[] = $item['rule_id'];
            }
        }

        return ['items' => $kept, 'silenced' => $silenced, 'summary' => [
            'reason' => null,
            'cost' => round($account['spend'], 2),
            'conversions' => round($account['results'], 2),
            'cpa' => $account['cpr'] !== null ? round($account['cpr'], 2) : null,
            'waste' => round(array_sum(array_map(static fn (array $i): float => $i['category'] === AdvisorCategory::Waste->value ? (float) $i['impact_amount'] : 0.0, $kept)), 2),
        ]];
    }

    protected function channelKey(): string
    {
        return 'meta_ads';
    }

    // ------------------------------------------------------------------ creative fatigue

    /** @return list<array<string, mixed>> */
    private function creativeFatigue(array $input): array
    {
        $cfg = (array) ($this->cfg['fatigue'] ?? []);
        $last7 = $input['period']['last7'];
        $prevStart = date('Y-m-d', strtotime($last7.' -7 days'));
        $rows = [];
        foreach ($input['ads'] ?? [] as $ad) {
            if (! in_array(strtoupper((string) ($ad['effective_status'] ?? 'ACTIVE')), ['ACTIVE', ''], true)) {
                continue;
            }
            $now = $this->window($ad['daily'], $last7, $input['period']['end']);
            $before = $this->window($ad['daily'], $prevStart, date('Y-m-d', strtotime($last7.' -1 day')));
            if ((float) ($cfg['min_spend_14d'] ?? 200) > $now['spend'] + $before['spend'] || $before['impressions'] < 1000 || $now['impressions'] < 1000 || $before['link_clicks'] <= 0) {
                continue;
            }
            if ($now['frequency'] === null || $now['frequency'] < (float) ($cfg['frequency_min'] ?? 1.8)) {
                continue;
            }
            $ctrNow = $now['link_clicks'] / $now['impressions'];
            $ctrBefore = $before['link_clicks'] / $before['impressions'];
            $drop = 1 - $ctrNow / $ctrBefore;
            if ($drop < (float) ($cfg['ctr_drop'] ?? 0.25)) {
                continue;
            }
            $rows[] = compact('ad', 'now', 'before', 'ctrNow', 'ctrBefore', 'drop');
        }
        usort($rows, static fn (array $a, array $b): int => $b['now']['spend'] <=> $a['now']['spend']);

        return array_map(function (array $row) use ($input): array {
            $ad = $row['ad'];
            $creative = $input['creatives'][$ad['creative_id'] ?? ''] ?? [];
            $campaign = $input['campaigns'][$ad['campaign_id'] ?? ''] ?? null;
            $cpr = static fn (array $w): ?float => $w['results'] > 0 ? round($w['spend'] / $w['results'], 2) : null;

            return $this->item(
                input: $input,
                category: AdvisorCategory::Ads,
                ruleId: 'creative-fatigue',
                keyParts: [$ad['id']],
                severity: $row['drop'] >= 0.4 ? 'high' : 'medium',
                impact: $row['now']['spend'],
                impactLabel: sprintf('%s / 7 gün yorgun kreatife', $this->money($input, $row['now']['spend'])),
                title: sprintf('Kreatif yoruldu: %s', $ad['name']),
                reason: sprintf(
                    'Son 7 günde günlük ortalama sıklık %s; bağlantı tıklama oranı önceki haftaya göre %%%d düştü (%s → %s). Aynı kitle reklamı çok gördü: yeni kreatif gerekiyor. İstersen AI ile yeni kreatif fikri ve metinleri hazırlat.',
                    number_format($row['now']['frequency'], 1, ',', '.'), (int) round($row['drop'] * 100),
                    $this->pct($row['ctrBefore']), $this->pct($row['ctrNow']),
                ),
                evidence: [
                    'ad' => $ad['name'], 'ad_id' => $ad['id'], 'campaign' => $campaign['name'] ?? null, 'objective' => $campaign['objective'] ?? null,
                    'weeks' => [
                        ['period' => 'Önceki 7 gün', 'cost' => round($row['before']['spend'], 2), 'impressions' => $row['before']['impressions'], 'frequency' => $row['before']['frequency'] !== null ? round($row['before']['frequency'], 2) : null, 'ctr' => round($row['ctrBefore'] * 100, 2), 'cpm' => $this->cpm($row['before']), 'conversions' => $row['before']['results'], 'cpa' => $cpr($row['before'])],
                        ['period' => 'Son 7 gün', 'cost' => round($row['now']['spend'], 2), 'impressions' => $row['now']['impressions'], 'frequency' => round($row['now']['frequency'], 2), 'ctr' => round($row['ctrNow'] * 100, 2), 'cpm' => $this->cpm($row['now']), 'conversions' => $row['now']['results'], 'cpa' => $cpr($row['now'])],
                    ],
                    'creative_title' => $creative['title'] ?? null, 'creative_body' => $creative['body'] ?? null, 'creative_cta' => $creative['cta'] ?? null, 'final_url' => $creative['link_url'] ?? null,
                ],
                checklist: ['Aynı reklam setine 2–3 yeni kreatif ekle (farklı görsel/video ve açı).', 'Yorulan reklamı yenileri teslimat almaya başlayınca durdur.', 'Kitle küçükse hedeflemeyi genişlet ya da Advantage+ kitleyi dene.'],
                copyText: null,
                baseline: ['frequency' => round($row['now']['frequency'], 2), 'ctr' => round($row['ctrNow'] * 100, 2)],
            );
        }, array_slice($rows, 0, (int) ($cfg['max_items'] ?? 3)));
    }

    // ------------------------------------------------------------------ audience saturation

    /** @return list<array<string, mixed>> */
    private function audienceSaturation(array $input): array
    {
        $cfg = (array) ($this->cfg['saturation'] ?? []);
        $rows = [];
        foreach ($input['campaigns'] ?? [] as $campaign) {
            if ($campaign['frequency_7d'] !== null && $campaign['frequency_7d'] >= (float) ($cfg['frequency_min'] ?? 2.5) && $campaign['spend_7d'] >= (float) ($cfg['min_spend_7d'] ?? 200)) {
                $rows[] = ['name' => $campaign['name'], 'frequency' => round($campaign['frequency_7d'], 1), 'cost' => round($campaign['spend_7d'], 2), 'objective' => $campaign['objective']];
            }
        }
        if ($rows === []) {
            return [];
        }
        usort($rows, static fn (array $a, array $b): int => $b['frequency'] <=> $a['frequency']);
        $cost = array_sum(array_column($rows, 'cost'));

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Audience,
            ruleId: 'audience-saturation',
            keyParts: [],
            severity: 'medium',
            impact: $cost,
            impactLabel: sprintf('%s / 7 gün doygun kitleye', $this->money($input, $cost)),
            title: sprintf('Kitle doyuyor (%d kampanya)', count($rows)),
            reason: sprintf('Bu kampanyalarda son 7 günün günlük ortalama sıklığı %s ve üzeri: aynı kişiler reklamı her gün birkaç kez görüyor. Haftalık sıklık bundan da yüksektir; maliyet artar, etki düşer.', number_format((float) ($cfg['frequency_min'] ?? 2.5), 1, ',', '.')),
            evidence: ['campaigns' => $rows],
            checklist: ['Hedef kitleyi genişlet (ilgi alanı yerine geniş kitle, benzer kitle yüzdesini artır).', 'Yeni kreatif ekle; aynı kreatifi uzun süre dönme.', 'Bütçe kitleye göre fazla ise düşür.'],
            copyText: null,
            baseline: ['frequency' => $rows[0]['frequency']],
        )];
    }

    // ------------------------------------------------------------------ learning

    /** @return list<array<string, mixed>> */
    private function learningLimited(array $input): array
    {
        $cfg = (array) ($this->cfg['learning'] ?? []);
        $goals = (array) ($this->cfg['conversion_goals'] ?? []);
        $target = (int) ($cfg['weekly_results_target'] ?? 50);
        $rows = [];
        $perCampaign = [];
        foreach ($input['adsets'] ?? [] as $adset) {
            if (strtoupper((string) $adset['effective_status']) !== 'ACTIVE' || ! in_array($adset['optimization_goal'], $goals, true)) {
                continue;
            }
            if ($adset['spend_7d'] < (float) ($cfg['min_spend_7d'] ?? 150) || $adset['results_7d'] >= (float) ($cfg['low_weekly_results'] ?? 15)) {
                continue;
            }
            $campaign = $input['campaigns'][$adset['campaign_id'] ?? ''] ?? null;
            $cpr = $adset['results'] > 0 ? $adset['spend'] / $adset['results'] : ($campaign['cpr'] ?? $input['account']['cpr']);
            $rows[] = [
                'name' => $adset['name'], 'campaign' => $campaign['name'] ?? null, 'conversions' => $adset['results_7d'], 'cost' => round($adset['spend_7d'], 2),
                'daily_budget' => $adset['daily_budget'] ?? $campaign['daily_budget'] ?? null,
                'needed_daily' => $cpr !== null ? round($cpr * $target / 7, 2) : null,
            ];
            $perCampaign[$adset['campaign_id']] = ($perCampaign[$adset['campaign_id']] ?? 0) + 1;
        }
        if ($rows === []) {
            return [];
        }
        $fragmented = count(array_filter($perCampaign, static fn (int $n): bool => $n >= 2));

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Audience,
            ruleId: 'learning-limited',
            keyParts: [],
            severity: 'medium',
            impact: array_sum(array_column($rows, 'cost')),
            impactLabel: sprintf('%d reklam seti öğrenmeden çıkamıyor', count($rows)),
            title: sprintf('Az sonuçlu reklam setleri (%d): öğrenme aşamasında takılı kalabilir', count($rows)),
            reason: sprintf(
                'Meta bir reklam setini optimize edebilmek için haftada yaklaşık %d sonuç ister. Bu setler son 7 günde bunun çok altında kaldı; büyük olasılıkla "Öğrenme sınırlı" durumdalar ve maliyet dalgalı.%s',
                $target, $fragmented > 0 ? sprintf(' %d kampanyada birden fazla küçük set var: birleştirmek sonuçları tek sette toplar.', $fragmented) : '',
            ),
            evidence: ['adsets' => $rows],
            checklist: array_values(array_filter([
                $fragmented > 0 ? 'Aynı kampanyadaki benzer reklam setlerini birleştir.' : null,
                'Bütçe yetmiyorsa "gerekli günlük bütçe" sütununa yaklaş ya da daha sık olan bir olaya (ör. form açma) optimize et.',
                'Öğrenme sürerken sık düzenleme yapma; her büyük değişiklik öğrenmeyi yeniden başlatır.',
            ])),
            copyText: null,
            baseline: ['adsets' => count($rows)],
        )];
    }

    // ------------------------------------------------------------------ waste

    /** @return list<array<string, mixed>> */
    private function spendWithoutResults(array $input): array
    {
        $cfg = (array) ($this->cfg['waste'] ?? []);
        $account = $input['account'];
        if ($account['results'] <= 0 || ($input['actions_level'] ?? 'none') === 'none') {
            return []; // no result data at all is a measurement question, not waste
        }
        $floor = max((float) ($cfg['min_spend'] ?? 200), $account['cpr'] !== null ? $account['cpr'] * (float) ($cfg['cpr_ratio'] ?? 2.0) : 0.0);
        $rows = [];
        foreach ($input['campaigns'] ?? [] as $campaign) {
            if ($campaign['conversion'] && $campaign['results'] <= 0 && $campaign['spend'] >= $floor && in_array(strtoupper((string) ($campaign['effective_status'] ?? 'ACTIVE')), ['ACTIVE', ''], true)) {
                $rows[] = ['name' => $campaign['name'], 'objective' => $campaign['objective'], 'cost' => round($campaign['spend'], 2), 'clicks' => $campaign['link_clicks']];
            }
        }
        if ($rows === []) {
            return [];
        }
        $cost = array_sum(array_column($rows, 'cost'));

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Waste,
            ruleId: 'spend-no-results',
            keyParts: [],
            severity: $cost / max(1.0, $account['spend']) >= 0.15 ? 'high' : 'medium',
            impact: $cost,
            impactLabel: sprintf('%s / %d gün sonuçsuz', $this->money($input, $cost), $input['period']['days']),
            title: sprintf('Sonuç getirmeyen dönüşüm kampanyası (%d)', count($rows)),
            reason: sprintf('Hesap sonuç alırken bu kampanyalar son %d günde hedeflediği sonuçtan hiç almadı (en az %s harcadı).', $input['period']['days'], $this->money($input, $floor)),
            evidence: ['campaigns' => $rows],
            checklist: ['Kampanyanın optimize ettiği olayın pikselde gerçekten tetiklendiğini kontrol et.', 'Kitle ve kreatifi başarılı kampanyayla karşılaştır.', 'Düzelmiyorsa bütçeyi kıs ve sonuç getiren kampanyaya aktar.'],
            copyText: null,
            baseline: ['cost' => $cost],
        )];
    }

    // ------------------------------------------------------------------ pixel / conversion sources

    /** @return list<array<string, mixed>> */
    private function pixelHealth(array $input): array
    {
        $sources = $input['conversion_sources'] ?? ['available' => false, 'items' => []];
        if (! $sources['available']) {
            return [];
        }
        $staleDays = (int) (($this->cfg['pixel'] ?? [])['stale_days'] ?? 3);
        $end = strtotime($input['period']['end'].' 23:59:59');
        $pixels = [];
        $customs = [];
        foreach ($sources['items'] as $source) {
            if ($source['type'] === 'PIXEL') {
                $pixels[$source['id']] = $source;
            } else {
                $customs[$source['id']] = $source;
            }
        }
        $spentLast7 = array_sum(array_map(static fn (array $c): float => $c['conversion'] ? $c['spend_7d'] : 0.0, $input['campaigns'] ?? [])) > 0;
        $issues = [];
        $severity = 'medium';
        foreach ($pixels as $pixel) {
            if ($pixel['is_unavailable'] === true) {
                $issues[] = ['action' => $pixel['name'] ?: 'Piksel '.$pixel['id'], 'issue' => 'Meta pikseli kullanılamaz olarak işaretliyor.', 'fix' => 'Events Manager\'da pikselin durumunu ve erişimi kontrol et.'];
                $severity = 'critical';

                continue;
            }
            if ($spentLast7 && $pixel['last_fired_time'] !== null && $end - strtotime($pixel['last_fired_time']) > $staleDays * 86400) {
                $issues[] = ['action' => $pixel['name'] ?: 'Piksel '.$pixel['id'], 'issue' => sprintf('Son veri %s tarihinde geldi; dönüşüm kampanyaları harcarken %d günden uzun süredir sessiz.', substr($pixel['last_fired_time'], 0, 10), $staleDays), 'fix' => 'Sitede piksel kodunun (veya CAPI bağlantısının) çalıştığını Events Manager → Test olayları ile doğrula.'];
                $severity = $severity === 'critical' ? 'critical' : 'high';
            }
        }
        foreach ($input['adsets'] ?? [] as $adset) {
            if (strtoupper((string) $adset['effective_status']) !== 'ACTIVE') {
                continue;
            }
            $promoted = $adset['promoted_object'] ?? [];
            $pixelId = isset($promoted['pixel_id']) ? (string) $promoted['pixel_id'] : null;
            if ($pixelId !== null && $pixels !== [] && ! isset($pixels[$pixelId])) {
                $issues[] = ['action' => $adset['name'], 'issue' => 'Reklam seti bu hesapta görünmeyen bir piksele ('.$pixelId.') optimize ediyor.', 'fix' => 'Doğru pikseli seç ya da pikselin hesaba paylaşıldığını kontrol et.'];
            }
            $customId = isset($promoted['custom_conversion_id']) ? (string) $promoted['custom_conversion_id'] : null;
            if ($customId !== null && isset($customs[$customId]) && ($customs[$customId]['is_archived'] === true || $customs[$customId]['is_unavailable'] === true)) {
                $issues[] = ['action' => $adset['name'], 'issue' => 'Arşivlenmiş/kullanılamaz özel dönüşüme optimize ediyor: '.($customs[$customId]['name'] ?? $customId), 'fix' => 'Etkin bir dönüşüm olayına geçir.'];
                $severity = $severity === 'critical' ? 'critical' : 'high';
            }
        }
        if ($issues === []) {
            return [];
        }

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Measurement,
            ruleId: 'pixel-health',
            keyParts: [],
            severity: $severity,
            impact: null,
            impactLabel: 'Ölçüm ve optimizasyon',
            title: sprintf('Piksel / dönüşüm kaynağı sorunu (%d)', count($issues)),
            reason: 'Meta, dönüşüm kampanyalarını pikselden gelen olaylara göre optimize eder. Olay gelmiyorsa ya da yanlış kaynağa bakılıyorsa harcama kör yapılır ve raporlar eksik kalır.',
            evidence: ['issues' => $issues],
            checklist: array_values(array_unique(array_column($issues, 'fix'))),
            copyText: null,
            baseline: null,
        )];
    }

    // ------------------------------------------------------------------ placements, devices, hours

    /** @return list<array<string, mixed>> */
    private function deliveryOutliers(array $input): array
    {
        $cfg = (array) ($this->cfg['delivery'] ?? []);
        $segments = [];
        $labels = ['placement' => 'Yerleşim', 'device' => 'Cihaz', 'hour' => 'Saat'];
        $groups = ($input['breakdowns'] ?? []) + (($input['hourly'] ?? []) !== [] ? ['hour' => $input['hourly']] : []);
        foreach ($groups as $type => $rows) {
            $spend = array_sum(array_column($rows, 'spend'));
            $clicks = array_sum(array_column($rows, 'clicks'));
            $impressions = array_sum(array_column($rows, 'impressions'));
            if ($spend <= 0 || $clicks <= 0 || $impressions <= 0) {
                continue;
            }
            $avgCpc = $spend / $clicks;
            $avgCtr = $clicks / $impressions;
            $minShare = (float) ($type === 'hour' ? ($cfg['hour_min_share'] ?? 0.05) : ($cfg['min_share'] ?? 0.10));
            foreach ($rows as $row) {
                $share = $row['spend'] / $spend;
                if ($share < $minShare || $row['impressions'] < 1000) {
                    continue;
                }
                $cpc = $row['clicks'] > 0 ? $row['spend'] / $row['clicks'] : null;
                $ctr = $row['clicks'] / $row['impressions'];
                $expensive = $cpc === null || $cpc >= $avgCpc * (float) ($cfg['cpc_ratio'] ?? 2.0);
                $weak = $ctr <= $avgCtr * (float) ($cfg['ctr_ratio'] ?? 0.4);
                if ($expensive || $weak) {
                    $segments[] = [
                        'type' => $labels[$type] ?? $type, 'segment' => $row['label'], 'cost' => round($row['spend'], 2), 'share' => round($share * 100),
                        'cpc_value' => $cpc !== null ? round($cpc, 2) : null, 'avg_cpc' => round($avgCpc, 2), 'ctr' => round($ctr * 100, 2), 'avg_ctr' => round($avgCtr * 100, 2),
                    ];
                }
            }
        }
        if ($segments === []) {
            return [];
        }
        usort($segments, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        $cost = array_sum(array_column($segments, 'cost'));

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Audience,
            ruleId: 'delivery-outliers',
            keyParts: [],
            severity: 'low',
            impact: $cost,
            impactLabel: sprintf('%s pahalı bölümlere gitti', $this->money($input, $cost)),
            title: sprintf('Pahalı yerleşim / cihaz / saat (%d)', count($segments)),
            reason: 'Hesap geneli kırılımda bu bölümler harcamanın önemli payını alıyor ama tık maliyeti ortalamanın en az 2 katı ya da tık oranı çok düşük. Kırılım tık üzerinden: sonuç verisi bu düzeyde toplanmıyor, karar vermeden önce kampanya raporunda sonuç maliyetine bak.',
            evidence: ['segments' => $segments],
            checklist: ['Ads Manager → Kırılım → Yerleşim/Cihaz/Saat ile sonuç maliyetini kontrol et.', 'Gerçekten pahalıysa Advantage+ yerleşimi kapatıp o yerleşimi çıkar ya da reklam zamanlamasını sınırla (ömür boyu bütçe gerekir).', 'Audience Network gibi düşük kaliteli trafikte bot/kaza tıklamasını değerlendir.'],
            copyText: null,
            baseline: ['cost' => $cost],
        )];
    }

    // ------------------------------------------------------------------ landing pages

    /** @return list<array<string, mixed>> */
    private function landingPages(array $input): array
    {
        $pages = $input['website']['pages'] ?? [];
        if ($pages === []) {
            return [];
        }
        $spendByUrl = [];
        foreach ($input['ads'] ?? [] as $ad) {
            $url = $input['creatives'][$ad['creative_id'] ?? '']['link_url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            $spend = $this->window($ad['daily'], $input['period']['start'], $input['period']['end']);
            $entry = $spendByUrl[$url] ?? ['url' => $url, 'cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
            $entry['cost'] += $spend['spend'];
            $entry['clicks'] += $spend['link_clicks'];
            $entry['conversions'] += $spend['results'];
            $spendByUrl[$url] = $entry;
        }
        $rows = [];
        $broken = false;
        foreach ($spendByUrl as $url => $entry) {
            if ($entry['cost'] < (float) (($this->cfg['landing'] ?? [])['min_spend'] ?? 100)) {
                continue;
            }
            $issues = AdvisorWebsiteReader::landingIssues($url, $pages);
            if ($issues !== []) {
                $broken = $broken || str_starts_with($issues[0], 'Sayfa hata');
                $rows[] = $entry + ['issues' => $issues, 'cost' => round($entry['cost'], 2)];
            }
        }
        if ($rows === []) {
            return [];
        }
        $cost = array_sum(array_column($rows, 'cost'));

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Landing,
            ruleId: 'landing-page-issues',
            keyParts: [],
            severity: $broken ? 'critical' : 'medium',
            impact: $cost,
            impactLabel: sprintf('%s harcama bu sayfalara gidiyor', $this->money($input, $cost)),
            title: sprintf('Reklamların gittiği sayfada sorun (%d)', count($rows)),
            reason: 'Reklam bağlantısının indiği sayfa web sitesi taramasında hata, yönlendirme ya da noindex gösteriyor. Tık parası ödenip ziyaretçi kaybediliyor olabilir.',
            evidence: ['pages' => $rows],
            checklist: ['Hata veren sayfayı düzelt ya da reklamın bağlantısını çalışan sayfaya çevir.', 'Yönlendirme varsa reklamda doğrudan son adresi kullan (UTM\'ler kaybolmasın).'],
            copyText: null,
            baseline: ['cost' => $cost],
        )];
    }

    // ------------------------------------------------------------------ change impact

    /** @return list<array<string, mixed>> */
    private function changeImpact(array $input): array
    {
        $changes = $input['changes'] ?? ['available' => false, 'items' => []];
        if (! $changes['available']) {
            return [];
        }
        $names = array_map(static fn (array $c): string => (string) $c['name'], $input['campaigns'] ?? []);
        $candidates = ChangeImpact::candidates($changes['items'], $input['campaign_daily'] ?? [], $names, $input['period']['end'], (array) ($this->cfg['change'] ?? []));

        return array_map(fn (array $c): array => $this->item(
            input: $input,
            category: AdvisorCategory::Change,
            ruleId: 'change-impact',
            keyParts: [$c['campaign_id'], $c['date']],
            severity: $c['increase'] >= 0.6 ? 'high' : 'medium',
            impact: $c['extra_cost'],
            impactLabel: sprintf('≈ %s fazladan ödendi', $this->money($input, $c['extra_cost'])),
            title: $c['cpa_after'] !== null
                ? sprintf('%s: sonuç başı maliyet %s tarihli değişiklikten sonra %%%d arttı', $c['campaign'], date('d.m', strtotime($c['date'])), (int) round($c['increase'] * 100))
                : sprintf('%s: %s tarihli değişiklikten sonra sonuç durdu', $c['campaign'], date('d.m', strtotime($c['date']))),
            reason: sprintf(
                'Değişiklikten önceki %d günde sonuç başı maliyet %s idi, sonraki %d günde %s. Zamanlama nedensellik kanıtı değildir; kreatif yorgunluğu ve sezon etkisini de düşün.',
                $c['before']['days'], $this->money($input, $c['cpa_before']), $c['after']['days'], $c['cpa_after'] !== null ? $this->money($input, $c['cpa_after']) : 'sonuç yok',
            ),
            evidence: ['campaign' => $c['campaign'], 'date' => $c['date'], 'before' => $c['before'], 'after' => $c['after'], 'cpa_before' => $c['cpa_before'], 'cpa_after' => $c['cpa_after'],
                'events' => array_map(static fn (array $e): array => ['type' => $e['type'], 'fields' => $e['object'], 'user' => $e['user']], $c['events'])],
            checklist: ['Ads Manager → Hesap geçmişi\'nde o günkü değişikliği aç.', 'Bütçe/teklif/hedefleme değişikliği ise geri almayı ya da kademeli uygulamayı değerlendir.', 'Büyük değişiklik öğrenmeyi sıfırlar: 7 gün bekleyip tekrar kontrol et.'],
            copyText: null,
            baseline: ['cpa_before' => $c['cpa_before'], 'cpa_after' => $c['cpa_after']],
        ), array_slice($candidates, 0, 2));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{spend: float, impressions: int, link_clicks: int, results: float, frequency: ?float} */
    private function window(array $daily, string $from, string $to): array
    {
        $sum = ['spend' => 0.0, 'impressions' => 0, 'link_clicks' => 0, 'results' => 0.0, 'frequency' => null];
        $weighted = 0.0;
        $weight = 0;
        foreach ($daily as $date => $day) {
            if ($date < $from || $date > $to) {
                continue;
            }
            $sum['spend'] += $day['spend'];
            $sum['impressions'] += $day['impressions'];
            $sum['link_clicks'] += $day['link_clicks'];
            $sum['results'] += $day['results'];
            if ($day['frequency'] !== null && $day['impressions'] > 0) {
                $weighted += $day['frequency'] * $day['impressions'];
                $weight += $day['impressions'];
            }
        }
        $sum['frequency'] = $weight > 0 ? $weighted / $weight : null;

        return $sum;
    }

    private function cpm(array $window): ?float
    {
        return $window['impressions'] > 0 ? round($window['spend'] / $window['impressions'] * 1000, 2) : null;
    }

    private function pct(float $ratio): string
    {
        return '%'.number_format($ratio * 100, 2, ',', '.');
    }
}
