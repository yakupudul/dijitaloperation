<?php

namespace App\Services\Advisor\GoogleAds;

use App\Enums\AdvisorCategory;
use App\Services\Advisor\Support\BuildsAdvisorItems;
use App\Services\Advisor\Support\ChangeImpact;
use App\Services\SeoTasks\SeoText;

/**
 * Google Ads advisor rules over the collector package (pure: no database, no AI).
 *
 * Every item carries evidence (numbers + source), the money at stake, and paste-ready output where
 * possible. Rules stay silent when their data is missing. Few items: grouped lists, capped open count.
 */
final class GoogleAdsAdvisorRuleEngine
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
        $this->cfg = (array) config('moxdop-advisor.google_ads', []);
        if (! ($input['bound'] ?? false)) {
            return ['items' => [], 'silenced' => ['not_bound'], 'summary' => ['reason' => 'not_bound']];
        }
        $account = $input['account'];
        if (! $account['has_data']) {
            return ['items' => [], 'silenced' => ['no_campaign_data'], 'summary' => ['reason' => 'no_campaign_data']];
        }
        if ($account['cost'] < (float) ($this->cfg['min_account_cost'] ?? 300)) {
            return ['items' => [], 'silenced' => ['low_spend'], 'summary' => ['reason' => 'low_spend', 'cost' => round($account['cost'], 2)]];
        }

        $items = array_merge(
            $this->negativeKeywords($input),
            $this->keywordOpportunities($input),
            $this->budget($input),
            $this->measurement($input),
            $this->landingPages($input),
            $this->ads($input),
            $this->qualityScore($input),
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
            'cost' => round($account['cost'], 2),
            'conversions' => round($account['conversions'], 2),
            'cpa' => $account['cpa'] !== null ? round($account['cpa'], 2) : null,
            'waste' => round(array_sum(array_map(static fn (array $i): float => $i['category'] === AdvisorCategory::Waste->value ? (float) $i['impact_amount'] : 0.0, $kept)), 2),
        ]];
    }

    // ------------------------------------------------------------------ waste: negatives

    /** @return list<array<string, mixed>> */
    private function negativeKeywords(array $input): array
    {
        $terms = $input['search_terms'] ?? [];
        if ($terms === []) {
            return [];
        }
        $cfg = (array) ($this->cfg['negatives'] ?? []);
        $account = $input['account'];
        $threshold = max((float) ($cfg['min_cost'] ?? 40), $account['cpa'] !== null ? (float) $account['cpa'] * (float) ($cfg['cpa_share'] ?? 0.5) : 0.0);
        $brandTokens = $this->brandTokens($input);
        $serviceTexts = $this->serviceTexts($input);
        $negatives = $input['negatives'] ?? [];
        $lowIntent = array_map(static fn (string $w): string => SeoText::fold($w), (array) ($cfg['low_intent_words'] ?? []));

        $exact = [];
        $review = [];
        foreach ($terms as $term) {
            if ($term['conversions'] > 0 || $term['clicks'] < (int) ($cfg['min_clicks'] ?? 3) || $term['cost'] < $threshold) {
                continue;
            }
            if (array_intersect($term['statuses'], ['EXCLUDED', 'ADDED_EXCLUDED']) !== [] || $this->coveredByNegative($term['term'], $negatives)) {
                continue;
            }
            if ($this->isBrandTerm($term['term'], $brandTokens)) {
                continue;
            }
            $row = [
                'term' => $term['term'],
                'cost' => round($term['cost'], 2),
                'clicks' => $term['clicks'],
                'impressions' => $term['impressions'],
                'low_intent' => $this->containsAny($term['term'], $lowIntent),
                'pmax' => $term['pmax'],
            ];
            // A term that names one of the brand's services is relevant traffic that does not convert:
            // the fix is usually the page or the ad, not a negative. Listed separately, never in the paste list.
            if (! $row['low_intent'] && $this->mentionsService($term['term'], $serviceTexts)) {
                $review[] = $row;
            } else {
                $exact[] = $row;
            }
        }
        usort($exact, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        usort($review, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        $exact = array_slice($exact, 0, (int) ($cfg['max_terms'] ?? 40));

        $words = $this->negativeWords($terms, $brandTokens, $serviceTexts, $negatives, $cfg);
        if ($exact === [] && $words === []) {
            return $review === [] ? [] : [$this->serviceTermsItem($input, $review)];
        }

        $wasted = array_sum(array_column($exact, 'cost')) + array_sum(array_column($words, 'cost_only_here'));
        $lines = array_merge(
            array_map(static fn (array $w): string => '"'.$w['word'].'"', $words),
            array_map(static fn (array $t): string => '['.$t['term'].']', $exact),
        );
        $share = $account['cost'] > 0 ? $wasted / $account['cost'] : 0.0;
        $severity = $share >= 0.10 ? 'high' : 'medium';
        $items = [$this->item(
            input: $input,
            category: AdvisorCategory::Waste,
            ruleId: 'negative-keywords',
            keyParts: [],
            severity: $severity,
            impact: $wasted,
            impactLabel: sprintf('%s / %d gün boşa harcama', $this->money($input, $wasted), $input['period']['days']),
            title: sprintf('Negatif anahtar kelime listesi (%d terim%s)', count($exact), $words !== [] ? ', '.count($words).' kelime' : ''),
            reason: sprintf(
                'Son %d günde hiç dönüşüm getirmeden en az %s harcayan %d arama terimi var; toplam %s (hesap harcamasının %%%d\'i). Mevcut negatiflerle çakışanlar, marka aramaları ve hizmet adı geçen terimler listeden çıkarıldı.',
                $input['period']['days'], $this->money($input, $threshold), count($exact), $this->money($input, $wasted), (int) round($share * 100),
            ),
            evidence: ['terms' => $exact, 'words' => $words, 'threshold' => round($threshold, 2), 'service_terms' => array_slice($review, 0, 15), 'pmax_terms' => count(array_filter($exact, static fn (array $t): bool => $t['pmax']))],
            checklist: array_values(array_filter([
                'Listeyi gözden geçir; işine yarayabilecek bir terim varsa sil.',
                'Google Ads → Araçlar → Paylaşılan kitaplık → Negatif anahtar kelime listeleri → yeni liste oluştur ve yapıştır ([ ] tam eşleme, " " sıralı eşleme).',
                'Listeyi arama kampanyalarına uygula.',
                count(array_filter($exact, static fn (array $t): bool => $t['pmax'])) > 0 ? 'Performance Max terimleri için listeyi hesap düzeyinde negatif olarak ekle (PMax kampanya negatiflerini kabul etmez).' : null,
                $review !== [] ? sprintf('Hizmet adı geçen %d dönüşümsüz terim ayrıca listelendi: negatif ekleme, açılış sayfasını ve reklam metnini kontrol et.', count($review)) : null,
            ])),
            copyText: implode("\n", $lines),
            baseline: ['wasted_cost' => round($wasted, 2), 'terms' => count($exact)],
        )];

        return $items;
    }

    /** Single words that only ever appear in non-converting terms (e.g. "ücretsiz", "staj"). */
    private function negativeWords(array $terms, array $brandTokens, array $serviceTexts, array $negatives, array $cfg): array
    {
        $stop = array_map(static fn (string $w): string => SeoText::fold($w), (array) ($cfg['stop_words'] ?? []));
        $serviceTokens = [];
        foreach ($serviceTexts as $text) {
            foreach (explode(' ', $text) as $token) {
                $serviceTokens[$token] = true;
            }
        }
        $converting = [];
        $wasted = [];
        $display = [];
        foreach ($terms as $term) {
            $tokens = [];
            foreach (preg_split('/\s+/u', trim($term['term'])) ?: [] as $word) {
                $token = SeoText::fold($word);
                if ($token !== '' && ! str_contains($token, ' ')) {
                    $tokens[$token] = true;
                    $display[$token] ??= mb_strtolower($word);
                }
            }
            foreach (array_keys($tokens) as $token) {
                if ($term['conversions'] > 0) {
                    $converting[$token] = true;
                } elseif ($term['clicks'] > 0) {
                    $wasted[$token]['terms'][] = $term['term'];
                    $wasted[$token]['cost'] = ($wasted[$token]['cost'] ?? 0.0) + $term['cost'];
                }
            }
        }
        $out = [];
        $minTerms = (int) ($cfg['word_min_terms'] ?? 3);
        $minCost = (float) ($cfg['min_cost'] ?? 40);
        foreach ($wasted as $token => $data) {
            if (mb_strlen($token) < 3 || isset($converting[$token]) || isset($serviceTokens[$token]) || in_array($token, $stop, true) || in_array($token, $brandTokens, true) || is_numeric($token)) {
                continue;
            }
            if (count($data['terms']) < $minTerms || $data['cost'] < $minCost || $this->coveredByNegative($token, $negatives)) {
                continue;
            }
            $out[] = ['word' => $display[$token] ?? $token, 'terms' => count($data['terms']), 'examples' => array_slice($data['terms'], 0, 4), 'cost' => round($data['cost'], 2), 'cost_only_here' => 0.0];
        }
        usort($out, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return array_slice($out, 0, 10);
    }

    private function serviceTermsItem(array $input, array $review): array
    {
        $cost = array_sum(array_column($review, 'cost'));

        return $this->item(
            input: $input,
            category: AdvisorCategory::Landing,
            ruleId: 'service-terms-not-converting',
            keyParts: [],
            severity: 'medium',
            impact: $cost,
            impactLabel: sprintf('%s / %d gün', $this->money($input, $cost), $input['period']['days']),
            title: sprintf('Hizmet aramaları tıklanıyor ama dönüşmüyor (%d terim)', count($review)),
            reason: 'Bu terimler markanın hizmetlerini adıyla arıyor; trafik doğru ama dönüşüm yok. Sorun genellikle açılış sayfası, teklif ya da reklam metnindedir; negatif eklemek yerine bunları kontrol et.',
            evidence: ['terms' => array_slice($review, 0, 20)],
            checklist: ['Bu terimlerin geldiği reklam grubunun açılış sayfasını aç: fiyat/iletişim/randevu bilgisi ilk ekranda mı?', 'Dönüşüm izlemesinin form ve telefon tıklamasında çalıştığını doğrula.', 'Reklam metninin arama terimiyle aynı hizmeti vaat ettiğini kontrol et.'],
            copyText: null,
            baseline: ['cost' => round($cost, 2)],
        );
    }

    // ------------------------------------------------------------------ growth: converting terms not added

    /** @return list<array<string, mixed>> */
    private function keywordOpportunities(array $input): array
    {
        $cfg = (array) ($this->cfg['keyword_opportunities'] ?? []);
        $existing = [];
        foreach ($input['keywords'] ?? [] as $keyword) {
            $existing[SeoText::fold((string) $keyword['text'])] = true;
        }
        $rows = [];
        foreach ($input['search_terms'] ?? [] as $term) {
            if ($term['conversions'] < (float) ($cfg['min_conversions'] ?? 2)) {
                continue;
            }
            if (array_intersect($term['statuses'], ['ADDED', 'ADDED_EXCLUDED', 'EXCLUDED']) !== [] || isset($existing[SeoText::fold($term['term'])])) {
                continue;
            }
            $rows[] = ['term' => $term['term'], 'conversions' => round($term['conversions'], 1), 'cost' => round($term['cost'], 2), 'clicks' => $term['clicks'], 'cpa' => round($term['cost'] / max(0.01, $term['conversions']), 2), 'pmax' => $term['pmax']];
        }
        if ($rows === []) {
            return [];
        }
        usort($rows, static fn (array $a, array $b): int => $b['conversions'] <=> $a['conversions']);
        $rows = array_slice($rows, 0, (int) ($cfg['max_terms'] ?? 25));
        $conversions = array_sum(array_column($rows, 'conversions'));

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Growth,
            ruleId: 'keyword-opportunities',
            keyParts: [],
            severity: 'medium',
            impact: null,
            impactLabel: sprintf('%s dönüşüm / %d gün bu terimlerden', rtrim(rtrim(number_format($conversions, 1, ',', '.'), '0'), ','), $input['period']['days']),
            title: sprintf('Dönüşüm getiren %d arama terimini anahtar kelime yap', count($rows)),
            reason: 'Bu terimler dönüşüm getiriyor ama anahtar kelime olarak eklenmemiş; eşleme değişirse trafiği kaybedebilirsin. Tam eşleme ile ekleyip teklifini ve reklam metnini kontrol altına al.',
            evidence: ['terms' => $rows],
            checklist: ['Terimleri ilgili reklam grubuna tam eşleme olarak ekle.', 'Reklam metninde terimin geçtiğinden emin ol.', 'PMax terimleri için ayrı bir arama kampanyası/reklam grubu düşün.'],
            copyText: implode("\n", array_map(static fn (array $r): string => '['.$r['term'].']', $rows)),
            baseline: ['conversions' => $conversions],
        )];
    }

    // ------------------------------------------------------------------ budget

    /** @return list<array<string, mixed>> */
    private function budget(array $input): array
    {
        $cfg = (array) ($this->cfg['budget'] ?? []);
        $account = $input['account'];
        $reference = $input['targets']['target_cpa'] ?? $account['cpa'];
        $limited = [];
        $waste = [];
        foreach ($input['campaigns'] ?? [] as $campaign) {
            if (strtoupper((string) $campaign['status']) !== 'ENABLED') {
                continue;
            }
            $lost = $campaign['lost_is_budget'];
            if ($lost !== null && $lost >= (float) ($cfg['lost_is_budget_min'] ?? 0.2) && $campaign['conversions'] >= (float) ($cfg['min_conversions'] ?? 3)
                && $reference !== null && $campaign['cpa'] !== null && $campaign['cpa'] <= $reference * (float) ($cfg['cpa_ratio_max'] ?? 1.0)) {
                $factor = min(1.0, $lost / max(0.05, 1 - $lost));
                $limited[] = [
                    'name' => $campaign['name'], 'cost' => round($campaign['cost'], 2), 'conversions' => round($campaign['conversions'], 1),
                    'cpa' => round($campaign['cpa'], 2), 'lost_is_budget' => round($lost * 100), 'daily_budget' => $campaign['budget_amount'],
                    'extra_cost' => round($campaign['cost'] * $factor, 2), 'extra_conversions' => round($campaign['conversions'] * $factor, 1),
                ];
            }
            $wasteFloor = max((float) ($cfg['waste_min_cost'] ?? 150), $account['cpa'] !== null ? $account['cpa'] * (float) ($cfg['waste_min_cost_ratio'] ?? 2.0) : 0.0);
            if ($campaign['conversions'] <= 0 && $campaign['cost'] >= $wasteFloor && $account['conversions'] > 0) {
                $waste[] = ['name' => $campaign['name'], 'cost' => round($campaign['cost'], 2), 'clicks' => $campaign['clicks'], 'channel' => $campaign['channel']];
            }
        }
        $items = [];
        if ($limited !== []) {
            usort($limited, static fn (array $a, array $b): int => $b['extra_conversions'] <=> $a['extra_conversions']);
            $extraConv = array_sum(array_column($limited, 'extra_conversions'));
            $wasteCost = array_sum(array_column($waste, 'cost'));
            $items[] = $this->item(
                input: $input,
                category: AdvisorCategory::Growth,
                ruleId: 'budget-limited-profitable',
                keyParts: [],
                severity: 'high',
                impact: array_sum(array_column($limited, 'extra_cost')),
                impactLabel: sprintf('≈ +%s dönüşüm / %d gün', rtrim(rtrim(number_format($extraConv, 1, ',', '.'), '0'), ','), $input['period']['days']),
                title: sprintf('Bütçesi yetmeyen kârlı kampanya (%d)', count($limited)),
                reason: sprintf(
                    'Bu kampanyalar %s CPA altında dönüşüm getiriyor ama gösterimlerin önemli kısmını bütçe yüzünden kaçırıyor.%s',
                    $input['targets']['target_cpa'] !== null ? 'hedef' : 'hesap ortalaması',
                    $waste !== [] ? sprintf(' Dönüşümsüz kampanyalardan (%s / %d gün) bütçe kaydırmak ek maliyet getirmez.', $this->money($input, $wasteCost), $input['period']['days']) : '',
                ),
                evidence: ['campaigns' => $limited, 'reference_cpa' => round((float) $reference, 2), 'waste_campaigns' => $waste],
                checklist: array_values(array_filter([
                    'Listedeki kampanyanın günlük bütçesini kademeli artır (bir seferde en fazla %20–30).',
                    $waste !== [] ? 'Artışı dönüşümsüz kampanyalardan kısarak karşıla.' : null,
                    '7 gün sonra CPA\'nın hedefin altında kaldığını kontrol et.',
                ])),
                copyText: null,
                baseline: ['conversions' => array_sum(array_column($limited, 'conversions')), 'cost' => array_sum(array_column($limited, 'cost'))],
            );
        }
        if ($waste !== []) {
            $cost = array_sum(array_column($waste, 'cost'));
            $items[] = $this->item(
                input: $input,
                category: AdvisorCategory::Waste,
                ruleId: 'budget-waste',
                keyParts: [],
                severity: $account['cost'] > 0 && $cost / $account['cost'] >= 0.15 ? 'high' : 'medium',
                impact: $cost,
                impactLabel: sprintf('%s / %d gün dönüşümsüz', $this->money($input, $cost), $input['period']['days']),
                title: sprintf('Dönüşüm getirmeyen kampanya (%d)', count($waste)),
                reason: sprintf('Hesap dönüşüm alırken bu kampanyalar son %d günde hiç dönüşüm getirmedi. Hedefi farklı değilse (bilinirlik vb.) bütçesini kıs veya durdur.', $input['period']['days']),
                evidence: ['campaigns' => $waste],
                checklist: ['Kampanyanın amacını kontrol et: dönüşüm hedefliyor mu?', 'Arama terimlerine ve açılış sayfasına bak; sorun oradaysa düzelt.', 'Değilse bütçeyi kıs veya durdur; bütçeyi kârlı kampanyalara aktar.'],
                copyText: null,
                baseline: ['cost' => $cost],
            );
        }

        return $items;
    }

    // ------------------------------------------------------------------ measurement

    /** @return list<array<string, mixed>> */
    private function measurement(array $input): array
    {
        $cfg = (array) ($this->cfg['measurement'] ?? []);
        $account = $input['account'];
        $actions = $input['conversion_actions'] ?? ['available' => false, 'items' => []];
        $items = [];

        if ($account['auto_tagging_enabled'] === false) {
            $items[] = $this->item(
                input: $input, category: AdvisorCategory::Measurement, ruleId: 'auto-tagging-off', keyParts: [], severity: 'high', impact: null,
                impactLabel: 'Ölçüm doğruluğu',
                title: 'Otomatik etiketleme kapalı',
                reason: 'Otomatik etiketleme (gclid) kapalıyken GA4 Google Ads trafiğini doğru ayıramaz ve içe aktarılan dönüşümler eksik kalır.',
                evidence: ['auto_tagging_enabled' => false],
                checklist: ['Google Ads → Yönetici → Hesap ayarları → Otomatik etiketleme: aç.', 'Sitede gclid parametresini silen yönlendirme olmadığını kontrol et.'],
                copyText: null, baseline: null,
            );
        }

        if ($actions['available']) {
            $enabledPrimary = array_values(array_filter($actions['items'], static fn (array $a): bool => $a['primary'] && strtoupper((string) $a['status']) === 'ENABLED'));
            if ($enabledPrimary === []) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Measurement, ruleId: 'no-primary-conversion', keyParts: [], severity: 'critical', impact: $account['cost'],
                    impactLabel: sprintf('%s harcama ölçülmüyor', $this->money($input, $account['cost'])),
                    title: 'Birincil dönüşüm işlemi yok',
                    reason: 'Hesapta etkin bir birincil dönüşüm işlemi yok. Akıllı teklif neyi optimize edeceğini bilmiyor ve raporlardaki "dönüşüm" sütunu anlamsız.',
                    evidence: ['actions' => array_map(static fn (array $a): array => ['name' => $a['name'], 'status' => $a['status'], 'primary' => $a['primary'], 'category' => $a['category']], $actions['items'])],
                    checklist: ['Form gönderimi / arama / randevu gibi gerçek iş sonucunu birincil dönüşüm yap.', 'Sayfa görüntüleme gibi ara adımları ikincil bırak.'],
                    copyText: null, baseline: null,
                );
            } elseif (($actions['daily_available'] ?? false) && $account['clicks'] >= (int) ($cfg['min_clicks_without_conversions'] ?? 150)
                && array_sum(array_map(static fn (array $a): float => (float) ($a['conversions'] ?? 0), $enabledPrimary)) <= 0) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Measurement, ruleId: 'primary-no-signal', keyParts: [], severity: 'high', impact: $account['cost'],
                    impactLabel: sprintf('%s tık, 0 dönüşüm', number_format($account['clicks'], 0, ',', '.')),
                    title: 'Birincil dönüşümler hiç tetiklenmiyor',
                    reason: sprintf('Son %d günde %s tıklama var ama birincil dönüşüm işlemlerinden hiçbiri kayıt almadı. Etiket bozulmuş ya da yanlış olaya bağlı olabilir.', $input['period']['days'], number_format($account['clicks'], 0, ',', '.')),
                    evidence: ['actions' => array_map(static fn (array $a): array => ['name' => $a['name'], 'category' => $a['category'], 'conversions' => $a['conversions']], $enabledPrimary)],
                    checklist: ['Google Tag Assistant ile siteyi aç ve formu test gönder; dönüşüm etiketinin tetiklendiğini gör.', 'Google Ads → Hedefler → Dönüşümler → Tanılama sekmesine bak.'],
                    copyText: null, baseline: null,
                );
            }

            $issues = [];
            $lowIntent = (array) ($cfg['low_intent_categories'] ?? []);
            $leadCategories = (array) ($cfg['lead_categories'] ?? []);
            foreach ($enabledPrimary as $action) {
                $category = strtoupper((string) $action['category']);
                if (in_array($category, $lowIntent, true)) {
                    $issues[] = ['action' => $action['name'], 'issue' => 'Düşük niyetli bir işlem birincil sayılıyor ('.$category.'); teklif sistemi gerçek müşteri yerine bunu optimize eder.', 'fix' => 'İkincil yap.'];
                }
                if (in_array($category, $leadCategories, true) && strtoupper((string) $action['counting_type']) === 'MANY_PER_CLICK') {
                    $issues[] = ['action' => $action['name'], 'issue' => 'Potansiyel müşteri işlemi "her dönüşüm" sayılıyor; aynı kişinin iki form gönderimi iki dönüşüm olur.', 'fix' => 'Sayım: "Bir" olarak değiştir.'];
                }
            }
            if ($issues !== []) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Measurement, ruleId: 'conversion-settings', keyParts: [], severity: 'medium', impact: null,
                    impactLabel: 'Teklif doğruluğu',
                    title: sprintf('Dönüşüm ayarı hatası (%d)', count($issues)),
                    reason: 'Birincil dönüşümler akıllı teklifin hedefidir; yanlış işlem ya da sayım, teklifi yanlış yöne iter ve CPA\'yı olduğundan iyi gösterir.',
                    evidence: ['issues' => $issues],
                    checklist: array_values(array_unique(array_map(static fn (array $i): string => $i['action'].': '.$i['fix'], $issues))),
                    copyText: null, baseline: null,
                );
            }
        }

        $ga4 = $input['ga4'] ?? ['available' => false];
        if ($ga4['available']) {
            $adsConv = (float) $account['conversions'];
            $ga4Events = (float) $ga4['key_events'];
            $min = (float) ($cfg['ga4_min_conversions'] ?? 10);
            $ratio = $ga4Events > 0 ? $adsConv / $ga4Events : null;
            $sessionRatio = $account['clicks'] > 0 ? $ga4['sessions'] / $account['clicks'] : null;
            $problems = [];
            if (max($adsConv, $ga4Events) >= $min && ($ratio === null || $ratio < (float) ($cfg['ga4_ratio_low'] ?? 0.5) || $ratio > (float) ($cfg['ga4_ratio_high'] ?? 2.0))) {
                $problems[] = sprintf('Google Ads %s dönüşüm, GA4 (google / cpc) %s temel etkinlik gösteriyor.', number_format($adsConv, 0, ',', '.'), number_format($ga4Events, 0, ',', '.'));
            }
            if ($account['clicks'] >= 200 && $sessionRatio !== null && $sessionRatio < (float) ($cfg['tracking_sessions_ratio_min'] ?? 0.3)) {
                $problems[] = sprintf('%s reklam tıklamasına karşı GA4\'te yalnızca %s google / cpc oturumu var.', number_format($account['clicks'], 0, ',', '.'), number_format((int) $ga4['sessions'], 0, ',', '.'));
            }
            if ($problems !== []) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Measurement, ruleId: 'ga4-mismatch', keyParts: [], severity: 'medium', impact: null,
                    impactLabel: 'Ölçüm tutarlılığı',
                    title: 'Google Ads ve GA4 sayıları tutmuyor',
                    reason: implode(' ', $problems).' İki taraf birebir tutmaz ama bu kadar fark genellikle eksik etiket, kapalı otomatik etiketleme ya da farklı olayın sayılmasından gelir.',
                    evidence: ['ads_conversions' => round($adsConv, 1), 'ga4_key_events' => round($ga4Events, 1), 'ads_clicks' => $account['clicks'], 'ga4_sessions' => $ga4['sessions']],
                    checklist: ['Google Ads ile GA4 bağlantısını (Yönetici → Bağlı hesaplar) kontrol et.', 'Aynı iş sonucunun iki tarafta aynı olaya bağlı olduğunu doğrula (ör. generate_lead).', 'Otomatik etiketlemenin açık olduğunu ve yönlendirmelerin gclid\'i silmediğini kontrol et.'],
                    copyText: null, baseline: null,
                );
            }
        }

        return $items;
    }

    // ------------------------------------------------------------------ landing pages

    /** @return list<array<string, mixed>> */
    private function landingPages(array $input): array
    {
        $cfg = (array) ($this->cfg['landing'] ?? []);
        $pages = $input['website']['pages'] ?? [];
        $account = $input['account'];
        $rows = [];
        $broken = 0;
        foreach ($input['landing_pages'] ?? [] as $landing) {
            if ($landing['cost'] < (float) ($cfg['min_cost'] ?? 100)) {
                continue;
            }
            $issues = [];
            $page = $pages[SeoText::urlKey($landing['url'])] ?? null;
            if ($page !== null) {
                if ($page['status_code'] !== null && $page['status_code'] >= 400) {
                    $issues[] = 'Sayfa hata veriyor ('.$page['status_code'].')';
                    $broken++;
                } elseif (is_string($page['final_url']) && $page['final_url'] !== '' && SeoText::urlKey($page['final_url']) !== SeoText::urlKey($landing['url'])) {
                    $issues[] = 'Başka adrese yönleniyor: '.$page['final_url'];
                }
                if ($page['noindex']) {
                    $issues[] = 'noindex (Google reklam kalitesini düşürebilir)';
                }
            }
            if ($landing['speed_score'] !== null && $landing['speed_score'] <= (int) ($cfg['speed_score_max'] ?? 4)) {
                $issues[] = 'Mobil hız puanı '.$landing['speed_score'].'/10';
            }
            if ($landing['mobile_friendly'] !== null && $landing['mobile_friendly'] < (float) ($cfg['mobile_friendly_min'] ?? 50)) {
                $issues[] = sprintf('Tıklamaların yalnızca %%%d\'i mobil uyumlu sayfaya', (int) round($landing['mobile_friendly']));
            }
            if ($landing['conversions'] <= 0 && $account['cpa'] !== null && $landing['cost'] >= 2 * $account['cpa']) {
                $issues[] = 'Hesap ortalama CPA\'sının 2 katı harcadı, dönüşüm yok';
            }
            if ($issues !== []) {
                $rows[] = ['url' => $landing['url'], 'cost' => round($landing['cost'], 2), 'clicks' => $landing['clicks'], 'conversions' => round($landing['conversions'], 1), 'issues' => $issues];
            }
        }
        if ($rows === []) {
            return [];
        }
        usort($rows, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        $cost = array_sum(array_column($rows, 'cost'));

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Landing,
            ruleId: 'landing-page-issues',
            keyParts: [],
            severity: $broken > 0 ? 'critical' : 'medium',
            impact: $cost,
            impactLabel: sprintf('%s harcama bu sayfalara gidiyor', $this->money($input, $cost)),
            title: sprintf('Açılış sayfası sorunları (%d sayfa)', count($rows)),
            reason: 'Reklam tıklamasının indiği sayfada sorun varsa hem dönüşüm düşer hem Google kalite puanını ve dolayısıyla tık maliyetini artırır. Sayfa verisi web sitesi taramasından ve Google Ads\'in kendi açılış sayfası raporundan geliyor.',
            evidence: ['pages' => array_slice($rows, 0, 15), 'website_matched' => $pages !== []],
            checklist: array_values(array_filter([
                $broken > 0 ? 'Hata veren sayfaları hemen düzelt ya da reklamın hedef URL\'sini çalışan sayfaya çevir.' : null,
                'Yavaş sayfalarda görselleri sıkıştır, gereksiz eklentileri kaldır; PageSpeed ile ölç.',
                'Dönüşümsüz sayfada teklif, iletişim ve form ilk ekranda mı kontrol et.',
            ])),
            copyText: null,
            baseline: ['cost' => $cost],
        )];
    }

    // ------------------------------------------------------------------ ads & assets

    /** @return list<array<string, mixed>> */
    private function ads(array $input): array
    {
        $items = [];
        $cfg = (array) ($this->cfg['ads'] ?? []);
        $ads = $input['ads'] ?? ['available' => false, 'items' => []];
        $campaigns = $input['campaigns'] ?? [];

        if ($ads['available']) {
            $weak = (array) ($cfg['weak_strengths'] ?? ['POOR', 'AVERAGE']);
            $groups = [];
            foreach ($ads['items'] as $ad) {
                if (strtoupper((string) $ad['status']) !== 'ENABLED' || ! in_array(strtoupper((string) $ad['ad_strength']), $weak, true) || $ad['ad_group_id'] === null) {
                    continue;
                }
                $cost = (float) ($ad['metrics']['cost'] ?? 0);
                if (($ads['metrics_available'] ?? false) && $cost < (float) ($cfg['min_cost'] ?? 50)) {
                    continue;
                }
                $group = $groups[$ad['ad_group_id']] ?? ['ad_group_id' => $ad['ad_group_id'], 'ad_group' => $ads['ad_groups'][$ad['ad_group_id']] ?? ('Reklam grubu '.$ad['ad_group_id']), 'campaign' => $campaigns[$ad['campaign_id']]['name'] ?? null, 'campaign_id' => $ad['campaign_id'], 'cost' => 0.0, 'strengths' => [], 'final_url' => $ad['final_urls'][0] ?? null];
                $group['cost'] += $cost;
                $group['strengths'][] = strtoupper((string) $ad['ad_strength']);
                $groups[$ad['ad_group_id']] = $group;
            }
            usort($groups, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
            foreach (array_slice($groups, 0, 3) as $group) {
                $poor = in_array('POOR', $group['strengths'], true);
                $items[] = $this->item(
                    input: $input,
                    category: AdvisorCategory::Ads,
                    ruleId: 'weak-ad-strength',
                    keyParts: [$group['ad_group_id']],
                    severity: $poor ? 'medium' : 'low',
                    impact: $group['cost'] > 0 ? $group['cost'] : null,
                    impactLabel: $group['cost'] > 0 ? sprintf('%s harcayan reklam grubu', $this->money($input, $group['cost'])) : 'Reklam kalitesi',
                    title: sprintf('Reklam gücü %s: %s', $poor ? 'zayıf' : 'ortalama', $group['ad_group']),
                    reason: 'Duyarlı arama reklamında başlık ve açıklama çeşitliliği az. Google daha az kombinasyon deneyebiliyor; gösterim ve tık oranı düşük kalır. İstersen AI ile metin taslağı hazırlat, onaylayıp kendin ekle.',
                    evidence: ['ad_group' => $group['ad_group'], 'ad_group_id' => $group['ad_group_id'], 'campaign' => $group['campaign'], 'campaign_id' => $group['campaign_id'], 'ad_strength' => array_values(array_unique($group['strengths'])), 'final_url' => $group['final_url'], 'cost' => round($group['cost'], 2)],
                    checklist: ['En az 10–15 farklı başlık ve 4 açıklama ekle.', 'Başlıklardan en az 2–3\'ü ana anahtar kelimeyi içersin.', 'Fayda, fiyat/teklif ve harekete geçirici mesaj ayrı başlıklarda olsun.', 'Sabitlemeyi (pin) en aza indir.'],
                    copyText: null,
                    baseline: ['cost' => round($group['cost'], 2)],
                );
            }
        }

        $library = $input['asset_library'] ?? ['available' => false, 'counts' => []];
        $hasSearch = array_filter($campaigns, static fn (array $c): bool => strtoupper((string) $c['channel']) === 'SEARCH' && strtoupper((string) $c['status']) === 'ENABLED') !== [];
        if ($library['available'] && $hasSearch) {
            $counts = $library['counts'];
            $missing = [];
            if (($counts['SITELINK'] ?? 0) < 4) {
                $missing[] = sprintf('Site bağlantısı: %d (en az 4 olmalı)', $counts['SITELINK'] ?? 0);
            }
            if (($counts['CALLOUT'] ?? 0) < 4) {
                $missing[] = sprintf('Açıklama metni (callout): %d (en az 4 olmalı)', $counts['CALLOUT'] ?? 0);
            }
            if (($counts['STRUCTURED_SNIPPET'] ?? 0) === 0) {
                $missing[] = 'Yapılandırılmış snippet yok';
            }
            if ($missing !== []) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Ads, ruleId: 'missing-assets', keyParts: [], severity: 'low', impact: null,
                    impactLabel: 'Reklam alanı ve tık oranı',
                    title: 'Reklam öğeleri eksik',
                    reason: 'Site bağlantısı, açıklama metni ve snippet reklamı büyütür, tık oranını ücretsiz artırır. Sayılar hesabın öğe kitaplığından; kampanyaya bağlı olup olmadıklarını Google Ads\'te kontrol et.',
                    evidence: ['missing' => $missing, 'counts' => $counts],
                    checklist: ['Hizmet sayfalarına giden 4–6 site bağlantısı ekle.', '4 kısa açıklama metni ekle (ör. "Ücretsiz muayene", "7/24 randevu").', 'Hizmetler başlığıyla bir yapılandırılmış snippet ekle.'],
                    copyText: null, baseline: null,
                );
            }
        }

        $recommendations = $input['recommendations'] ?? ['available' => false, 'items' => []];
        if ($recommendations['available']) {
            $allow = (array) ($this->cfg['google_recommendations'] ?? []);
            $grouped = [];
            foreach ($recommendations['items'] as $rec) {
                $label = $allow[$rec['type']] ?? null;
                if ($label === null) {
                    continue;
                }
                $grouped[$label]['count'] = ($grouped[$label]['count'] ?? 0) + 1;
                if ($rec['campaign_id'] !== null && isset($campaigns[$rec['campaign_id']])) {
                    $grouped[$label]['campaigns'][$campaigns[$rec['campaign_id']]['name']] = true;
                }
            }
            if ($grouped !== []) {
                $rows = [];
                foreach ($grouped as $label => $data) {
                    $rows[] = ['label' => $label, 'count' => $data['count'], 'campaigns' => array_keys($data['campaigns'] ?? [])];
                }
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Ads, ruleId: 'google-recommendations', keyParts: [], severity: 'low', impact: null,
                    impactLabel: 'Google önerisi (süzülmüş)',
                    title: sprintf('Google\'ın önerilerinden değerli olanlar (%d)', array_sum(array_column($rows, 'count'))),
                    reason: 'Google Ads\'in kendi öneri listesinden yalnızca öğe, reklam ve etiket önerileri alındı. Bütçe artırma, geniş eşleme ve otomatik teklif önerileri bilerek gösterilmiyor.',
                    evidence: ['recommendations' => $rows, 'observed_date' => $recommendations['observed_date']],
                    checklist: ['Google Ads → Öneriler sayfasında bu türleri aç ve tek tek incele.', 'Otomatik uygulamayı kapalı tut; önerileri elle uygula.'],
                    copyText: null, baseline: null,
                );
            }
        }

        return $items;
    }

    // ------------------------------------------------------------------ quality score

    /** @return list<array<string, mixed>> */
    private function qualityScore(array $input): array
    {
        $cfg = (array) ($this->cfg['quality'] ?? []);
        $rows = [];
        $components = ['ad_relevance' => 0, 'landing_page_experience' => 0, 'expected_ctr' => 0];
        foreach ($input['keywords'] ?? [] as $keyword) {
            if ($keyword['quality_score'] === null || $keyword['quality_score'] > (int) ($cfg['max_score'] ?? 4) || strtoupper((string) $keyword['status']) !== 'ENABLED' || $keyword['cost'] < (float) ($cfg['min_cost'] ?? 50)) {
                continue;
            }
            $weak = [];
            foreach (array_keys($components) as $component) {
                if (strtoupper((string) $keyword[$component]) === 'BELOW_AVERAGE') {
                    $weak[] = $component;
                    $components[$component]++;
                }
            }
            $rows[] = ['keyword' => $keyword['text'], 'match_type' => $keyword['match_type'], 'quality_score' => $keyword['quality_score'], 'cost' => round($keyword['cost'], 2), 'conversions' => round($keyword['conversions'], 1), 'weak' => $weak];
        }
        if ($rows === []) {
            return [];
        }
        usort($rows, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        $cost = array_sum(array_column($rows, 'cost'));
        $labels = ['ad_relevance' => 'reklam alaka düzeyi', 'landing_page_experience' => 'açılış sayfası deneyimi', 'expected_ctr' => 'beklenen tık oranı'];
        arsort($components);
        $dominant = array_key_first($components);
        $checklist = [
            'ad_relevance' => 'Anahtar kelimeyi reklam başlığına ekle; farklı niyetteki kelimeleri ayrı reklam gruplarına böl.',
            'landing_page_experience' => 'Anahtar kelimenin hizmetini anlatan sayfaya yönlendir; sayfayı hızlandır.',
            'expected_ctr' => 'Başlıkları daha net teklif ve fayda ile yeniden yaz; öğeleri (site bağlantısı vb.) ekle.',
        ];

        return [$this->item(
            input: $input,
            category: AdvisorCategory::Quality,
            ruleId: 'low-quality-score',
            keyParts: [],
            severity: 'medium',
            impact: $cost,
            impactLabel: sprintf('%s düşük kalite puanıyla harcandı', $this->money($input, $cost)),
            title: sprintf('Düşük kalite puanlı ama harcayan anahtar kelime (%d)', count($rows)),
            reason: sprintf('Kalite puanı %d ve altı olan kelimeler aynı sıra için daha pahalı tıklama öder. En sık zayıf bileşen: %s.', (int) ($cfg['max_score'] ?? 4), $components[$dominant] > 0 ? $labels[$dominant] : 'belirtilmemiş'),
            evidence: ['keywords' => array_slice($rows, 0, 20), 'weak_components' => $components],
            checklist: array_values(array_unique(array_filter([$components[$dominant] > 0 ? $checklist[$dominant] : null, $checklist['ad_relevance'], $checklist['landing_page_experience']]))),
            copyText: null,
            baseline: ['cost' => $cost, 'avg_quality_score' => round(array_sum(array_column($rows, 'quality_score')) / count($rows), 1)],
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
                ? sprintf('%s: CPA %s tarihli değişiklikten sonra %%%d arttı', $c['campaign'], date('d.m', strtotime($c['date'])), (int) round($c['increase'] * 100))
                : sprintf('%s: %s tarihli değişiklikten sonra dönüşüm durdu', $c['campaign'], date('d.m', strtotime($c['date']))),
            reason: sprintf(
                'Değişiklikten önceki %d günde CPA %s idi, sonraki %d günde %s. Aynı gün yapılan değişiklikler aşağıda. Zamanlama nedensellik kanıtı değildir; mevsim ve rakip etkisini de düşün.',
                $c['before']['days'], $this->money($input, $c['cpa_before']), $c['after']['days'], $c['cpa_after'] !== null ? $this->money($input, $c['cpa_after']) : 'dönüşüm yok',
            ),
            evidence: ['campaign' => $c['campaign'], 'date' => $c['date'], 'before' => $c['before'], 'after' => $c['after'], 'cpa_before' => $c['cpa_before'], 'cpa_after' => $c['cpa_after'],
                'events' => array_map(static fn (array $e): array => ['type' => $e['resource_type'], 'operation' => $e['operation'], 'fields' => $e['changed_fields'], 'user' => $e['user']], $c['events'])],
            checklist: ['Google Ads → Değişiklik geçmişi\'nde o günkü değişikliği aç.', 'Değişiklik teklif/bütçe/hedefleme ise geri almayı veya kademeli uygulamayı değerlendir.', '7 gün sonra CPA\'yı tekrar kontrol et.'],
            copyText: null,
            baseline: ['cpa_before' => $c['cpa_before'], 'cpa_after' => $c['cpa_after']],
        ), array_slice($candidates, 0, 2));
    }

    // ------------------------------------------------------------------ helpers

    /** Does an existing negative (exact / phrase / broad semantics) already block this term? */
    public function coveredByNegative(string $term, array $negatives): bool
    {
        $folded = SeoText::fold($term);
        $termTokens = explode(' ', $folded);
        foreach ($negatives as $negative) {
            $neg = SeoText::fold(trim((string) $negative['text'], '[]"+ '));
            if ($neg === '') {
                continue;
            }
            $match = strtoupper((string) ($negative['match_type'] ?? 'BROAD'));
            $covered = match ($match) {
                'EXACT' => $folded === $neg,
                'PHRASE' => str_contains(' '.$folded.' ', ' '.$neg.' '),
                default => array_diff(explode(' ', $neg), $termTokens) === [],
            };
            if ($covered) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function brandTokens(array $input): array
    {
        $name = (string) ($input['asset']['brand_name'] ?? '');
        $domain = (string) ($input['website']['domain'] ?? '');
        $tokens = array_merge(explode(' ', SeoText::fold($name)), explode(' ', SeoText::fold(preg_replace('/\.(com|net|org|tr|com\.tr)$/', '', $domain) ?? '')));

        $generic = (array) config('moxdop-seo-tasks.geo.generic_name_words', []);

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => mb_strlen($t) >= 4 && ! in_array($t, $generic, true))));
    }

    private function isBrandTerm(string $term, array $brandTokens): bool
    {
        $folded = SeoText::fold($term);
        foreach ($brandTokens as $token) {
            if (str_contains(' '.$folded.' ', ' '.$token.' ') || str_contains(str_replace(' ', '', $folded), $token)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> folded service names, aliases and matching keywords */
    private function serviceTexts(array $input): array
    {
        $texts = [];
        foreach ($input['offerings'] ?? [] as $offering) {
            foreach (array_merge([$offering['name']], $offering['names'] ?? [], $offering['keywords'] ?? []) as $text) {
                $folded = SeoText::fold((string) $text);
                if (mb_strlen($folded) >= 3) {
                    $texts[$folded] = $folded;
                }
            }
        }

        return array_values($texts);
    }

    private function mentionsService(string $term, array $serviceTexts): bool
    {
        $folded = ' '.SeoText::fold($term).' ';
        foreach ($serviceTexts as $text) {
            if (str_contains($folded, ' '.$text.' ')) {
                return true;
            }
        }

        return false;
    }

    private function containsAny(string $term, array $foldedWords): bool
    {
        $folded = ' '.SeoText::fold($term).' ';
        foreach ($foldedWords as $word) {
            if ($word !== '' && str_contains($folded, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }

    protected function channelKey(): string
    {
        return 'google_ads';
    }
}
