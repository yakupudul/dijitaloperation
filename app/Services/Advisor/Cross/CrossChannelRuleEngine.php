<?php

namespace App\Services\Advisor\Cross;

use App\Enums\AdvisorCategory;
use App\Services\Advisor\Support\BuildsAdvisorItems;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\SeoTasks\SeoText;

/**
 * Cross-channel rules (pure): what one channel knows that another should act on.
 *  - A search term converts in Google Ads but the site has no page for it and ranks poorly organically.
 *  - The brand pays for its own name while it already ranks first organically (a test, not a verdict).
 *  - People find the Business Profile with a search the website has no content for.
 *  - Faz 7 consistency: Business Profile phone / website vs the site, Google Ads landing pages and Meta ad
 *    destinations on hosts that are not the brand's website.
 */
final class CrossChannelRuleEngine
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
        $this->cfg = (array) config('moxdop-advisor.cross', []);
        if (! ($input['bound'] ?? false)) {
            return ['items' => [], 'silenced' => ['not_bound'], 'summary' => ['reason' => 'not_bound']];
        }
        $input['account'] = ['cost' => max(1.0, array_sum(array_column($input['ads_terms'], 'cost')))];
        $brandTokens = $this->brandTokens($input);

        $items = array_merge(
            $this->adsTermsWithoutOrganicPage($input, $brandTokens),
            $this->paidBrandSearch($input, $brandTokens),
            $this->gbpSearchesWithoutContent($input, $brandTokens),
            $this->consistencyRules($input),
        );
        usort($items, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return ['items' => $items, 'silenced' => [], 'summary' => ['reason' => null, 'waste' => round(array_sum(array_map(static fn (array $i): float => $i['category'] === AdvisorCategory::Waste->value ? (float) $i['impact_amount'] : 0.0, $items)), 2)]];
    }

    protected function channelKey(): string
    {
        return 'cross_channel';
    }

    /** @return list<array<string, mixed>> */
    private function adsTermsWithoutOrganicPage(array $input, array $brandTokens): array
    {
        $rows = [];
        foreach ($input['ads_terms'] as $term) {
            if ($term['conversions'] < (float) ($this->cfg['ads_term_min_conversions'] ?? 2) || $this->isBrand($term['term'], $brandTokens)) {
                continue;
            }
            $organic = $input['gsc_queries'][SeoText::fold($term['term'])] ?? null;
            $position = $organic['position'] ?? null;
            if ($position !== null && $position <= (float) ($this->cfg['organic_good_position'] ?? 10)) {
                continue;
            }
            if ($this->matchingPage($term['term'], $input['pages']) !== null) {
                continue;
            }
            $rows[] = ['term' => $term['term'], 'conversions' => round($term['conversions'], 1), 'cost' => round($term['cost'], 2), 'cpa' => round($term['cost'] / max(0.01, $term['conversions']), 2), 'organic' => $position !== null ? number_format($position, 1, ',', '.').'. sıra' : 'görünmüyor'];
        }
        if ($rows === []) {
            return [];
        }
        usort($rows, static fn (array $a, array $b): int => $b['conversions'] <=> $a['conversions']);
        $rows = array_slice($rows, 0, (int) ($this->cfg['ads_term_max_items'] ?? 20));
        $cost = array_sum(array_column($rows, 'cost'));

        return [$this->item(
            input: $input, category: AdvisorCategory::Growth, ruleId: 'ads-term-no-organic-page', keyParts: [], severity: 'medium', impact: $cost,
            impactLabel: sprintf('%s reklam harcaması bu terimlere gidiyor', $this->money($input, $cost)),
            title: sprintf('Reklamda dönüşen %d arama için sitede sayfa yok', count($rows)),
            reason: 'Bu aramalar Google Ads\'te dönüşüm getiriyor; yani gerçek talep var. Sitede bu konuya ayrılmış bir sayfa yok ve organikte ilk 10\'da değilsin. Bir hizmet/rehber sayfası hem organik trafik getirir hem de reklamın kalite puanını ve maliyetini iyileştirir.',
            evidence: ['terms' => $rows],
            checklist: ['Listedeki terimleri konu başına grupla; her grup için bir sayfa ya da mevcut hizmet sayfasına bölüm planla (SEO Görevleri\'ne içerik olarak ekle).', 'Yeni sayfayı ilgili reklam grubunun açılış sayfası olarak da dene.'],
            copyText: implode("\n", array_column($rows, 'term')),
            baseline: ['terms' => count($rows)],
        )];
    }

    /** @return list<array<string, mixed>> */
    private function paidBrandSearch(array $input, array $brandTokens): array
    {
        if (! $input['gsc_available'] || $brandTokens === []) {
            return [];
        }
        $cost = 0.0;
        $clicks = 0;
        $terms = [];
        foreach ($input['ads_terms'] as $term) {
            if ($this->isBrand($term['term'], $brandTokens)) {
                $cost += $term['cost'];
                $clicks += $term['clicks'];
                $terms[] = $term['term'];
            }
        }
        if ($cost < (float) ($this->cfg['brand_paid_min_cost'] ?? 300)) {
            return [];
        }
        $impressions = 0;
        $weighted = 0.0;
        foreach ($input['gsc_queries'] as $query => $row) {
            if ($this->isBrand((string) $query, $brandTokens) && $row['position'] !== null) {
                $impressions += $row['impressions'];
                $weighted += $row['position'] * $row['impressions'];
            }
        }
        if ($impressions < (int) ($this->cfg['brand_organic_min_impressions'] ?? 100)) {
            return [];
        }
        $position = $weighted / $impressions;
        if ($position > (float) ($this->cfg['brand_organic_max_position'] ?? 1.5)) {
            return [];
        }

        return [$this->item(
            input: $input, category: AdvisorCategory::Waste, ruleId: 'paid-brand-search', keyParts: [], severity: 'low', impact: $cost,
            impactLabel: sprintf('%s / 30 gün marka aramasına', $this->money($input, $cost)),
            title: 'Organikte 1. olduğun marka aramasına reklam ödeniyor',
            reason: sprintf(
                'Son 30 günde marka adı geçen aramalara %s harcandı (%d tık). Search Console\'da bu aramalarda ortalama sıran %s. Rakipler marka adına teklif vermiyorsa reklamı kısa süre durdurmak tıklamaların çoğunu organikten ücretsiz alabilir. Bu bir test önerisi: rakip teklif veriyorsa reklam gerekli olabilir.',
                $this->money($input, $cost), $clicks, number_format($position, 1, ',', '.'),
            ),
            evidence: ['terms' => array_map(static fn (string $t): array => ['term' => $t], array_slice($terms, 0, 15)), 'organic_position' => round($position, 2), 'organic_impressions' => $impressions],
            checklist: ['Google Ads → Açık artırma analizi\'nde marka kampanyasında rakip var mı bak.', 'Rakip yoksa marka kampanyasını 2 hafta durdur; toplam (reklam + organik) marka tıklamasını karşılaştır.', 'Toplam düşmediyse bütçeyi başka kampanyaya aktar; düştüyse geri aç.'],
            copyText: null,
            baseline: ['cost' => round($cost, 2), 'position' => round($position, 2)],
        )];
    }

    /** @return list<array<string, mixed>> */
    private function gbpSearchesWithoutContent(array $input, array $brandTokens): array
    {
        $rows = [];
        foreach ($input['gbp_keywords'] as $keyword => $impressions) {
            if ($impressions < (int) ($this->cfg['gbp_keyword_min_impressions'] ?? 40) || $this->isBrand((string) $keyword, $brandTokens)) {
                continue;
            }
            if ($this->matchingPage((string) $keyword, $input['pages']) !== null) {
                continue;
            }
            $organic = $input['gsc_queries'][SeoText::fold((string) $keyword)] ?? null;
            $rows[] = ['keyword' => (string) $keyword, 'impressions' => $impressions, 'organic' => ($organic['position'] ?? null) !== null ? number_format($organic['position'], 1, ',', '.').'. sıra' : 'görünmüyor'];
        }
        if ($rows === []) {
            return [];
        }
        $rows = array_slice($rows, 0, (int) ($this->cfg['gbp_keyword_max_items'] ?? 20));

        return [$this->item(
            input: $input, category: AdvisorCategory::Growth, ruleId: 'gbp-search-no-site-content', keyParts: [], severity: 'low', impact: null,
            impactLabel: sprintf('%s görüntülenme İşletme Profili\'nde', number_format(array_sum(array_column($rows, 'impressions')), 0, ',', '.')),
            title: sprintf('İşletme Profili aramalarında olup sitede karşılığı olmayan konu (%d)', count($rows)),
            reason: 'İnsanlar yerel aramada işletmeyi bu kelimelerle buluyor ama sitede bu konuya ayrılmış bir sayfa yok. Aynı talep organik aramada da var; sayfa açmak ya da mevcut hizmet sayfasına bölüm eklemek hem site hem profil için alakayı artırır.',
            evidence: ['keywords' => $rows],
            checklist: ['Listedeki konuları SEO Görevleri\'ndeki içerik önerileriyle karşılaştır; yoksa yeni içerik olarak planla.', 'Profil hizmet listesinde de aynı adları kullan.'],
            copyText: implode("\n", array_column($rows, 'keyword')),
            baseline: ['keywords' => count($rows)],
        )];
    }

    /** @param list<array{url: string, text: string}> $pages */
    private function matchingPage(string $phrase, array $pages): ?string
    {
        $threshold = (float) ($this->cfg['page_match_overlap'] ?? 0.8);
        foreach ($pages as $page) {
            if ($page['text'] !== '' && SeoText::tokenOverlap($page['text'], $phrase) >= $threshold) {
                return $page['url'];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function brandTokens(array $input): array
    {
        $generic = (array) config('moxdop-seo-tasks.geo.generic_name_words', []);
        $domain = (string) preg_replace('/\.(com|net|org|tr|com\.tr)$/', '', (string) ($input['asset']['name'] ?? ''));
        $tokens = array_merge(explode(' ', SeoText::fold((string) ($input['asset']['brand_name'] ?? ''))), explode(' ', SeoText::fold($domain)));

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => mb_strlen($t) >= 4 && ! in_array($t, $generic, true))));
    }

    private function isBrand(string $text, array $brandTokens): bool
    {
        $folded = ' '.SeoText::fold($text).' ';
        foreach ($brandTokens as $token) {
            if (str_contains($folded, ' '.$token.' ') || str_contains(str_replace(' ', '', $folded), $token)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function consistencyRules(array $input): array
    {
        $c = $input['consistency'] ?? null;
        if (! is_array($c) || ($c['site_hosts'] ?? []) === []) {
            return [];
        }
        $siteHosts = (array) $c['site_hosts'];
        $allowed = array_map('strtolower', (array) ($this->cfg['offsite_allowed_hosts'] ?? []));
        $isSite = static function (string $host) use ($siteHosts): bool {
            foreach ($siteHosts as $site) {
                if ($host === $site || str_ends_with($host, '.'.$site) || str_ends_with($site, '.'.$host)) {
                    return true;
                }
            }

            return false;
        };
        $isAllowed = static fn (string $host): bool => collect($allowed)->contains(fn (string $a): bool => $host === $a || str_ends_with($host, '.'.$a));
        $items = [];

        foreach ((array) ($c['gbp_profiles'] ?? []) as $profile) {
            $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url((string) ($profile['website_uri'] ?? ''), PHP_URL_HOST))) ?? '';
            if ($host === '' || ! $isSite($host)) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Measurement, ruleId: 'gbp-website-mismatch', keyParts: [(string) $profile['name']], severity: $host === '' ? 'medium' : 'high',
                    impact: null, impactLabel: 'Profilden siteye giden ziyaretler',
                    title: $host === '' ? 'İşletme Profili\'nde web sitesi yok: '.$profile['name'] : 'İşletme Profili başka bir siteye yönlendiriyor: '.$profile['name'],
                    reason: $host === '' ? 'Profilde web sitesi alanı boş; haritadan gelen kişiler siteye ulaşamıyor.' : sprintf('Profildeki site %s, markanın sitesi %s. Profilden gelen ziyaretler ve dönüşümler yanlış yere gidiyor.', $host, implode(', ', $siteHosts)),
                    evidence: ['profile' => $profile['name'], 'gbp_website' => $profile['website_uri'] ?? null, 'site_hosts' => $siteHosts],
                    checklist: ['İşletme Profili → Bilgileri düzenle → Web sitesi alanına markanın sitesini (UTM ile) yaz.'],
                    copyText: null, baseline: null,
                );
            }
            $gbpKeys = array_values(array_filter(array_map(static fn ($p): ?string => WhatsAppContactLinker::key((string) $p), (array) ($profile['phones'] ?? []))));
            if (($c['site_phones_read'] ?? false) && $gbpKeys !== [] && ($c['site_phones'] ?? []) !== [] && array_intersect($gbpKeys, (array) $c['site_phones']) === []) {
                $items[] = $this->item(
                    input: $input, category: AdvisorCategory::Measurement, ruleId: 'nap-phone-mismatch', keyParts: [(string) $profile['name']], severity: 'medium',
                    impact: null, impactLabel: 'Tutarlı iletişim bilgisi (NAP) yerel sıralamayı destekler',
                    title: 'Sitedeki telefon İşletme Profili ile uyuşmuyor: '.$profile['name'],
                    reason: sprintf('Profildeki telefon (%s) ana sayfa / iletişim sayfasında geçmiyor; sitede %s var. Google ve kullanıcılar tutarsız numara görür.', implode(', ', (array) $profile['phones']), implode(', ', array_map(static fn (string $k): string => '…'.substr($k, -7), (array) $c['site_phones']))),
                    evidence: ['gbp_phones' => $profile['phones'], 'site_phone_keys' => $c['site_phones']],
                    checklist: ['Doğru numarayı belirle.', 'Site (üst bilgi, altbilgi, iletişim) ve profilde aynı numarayı kullan.'],
                    copyText: null, baseline: null,
                );
            }
        }

        $adsOff = array_filter((array) ($c['ads_landing_hosts'] ?? []), static fn (float $cost, string $host): bool => $cost > 0 && ! $isSite($host) && ! $isAllowed($host), ARRAY_FILTER_USE_BOTH);
        if ($adsOff !== []) {
            arsort($adsOff);
            $cost = array_sum($adsOff);
            $items[] = $this->item(
                input: $input, category: AdvisorCategory::Landing, ruleId: 'ads-landing-offsite', keyParts: [], severity: 'medium', impact: $cost,
                impactLabel: sprintf('%s son 30 günde başka alan adına gitti', $this->money($input, $cost)),
                title: 'Google Ads tıklamaları markanın sitesi dışındaki adreslere gidiyor',
                reason: sprintf('Açılış sayfası %s olan reklamlar var; markanın sitesi %s. Eski alan adı, test sayfası ya da yanlış URL olabilir; ölçüm ve kalite puanı etkilenir.', implode(', ', array_keys($adsOff)), implode(', ', $siteHosts)),
                evidence: ['hosts' => $adsOff, 'site_hosts' => $siteHosts],
                checklist: ['Reklamların nihai URL\'lerini kontrol et.', 'Doğru sayfaya yönlendir ya da bilinçli bir kampanya sayfasıysa Yöntem Kütüphanesi\'nde izinli alan adlarına ekle.'],
                copyText: null, baseline: null,
            );
        }
        $metaOff = array_filter((array) ($c['meta_hosts'] ?? []), static fn (int $count, string $host): bool => ! $isSite($host) && ! $isAllowed($host), ARRAY_FILTER_USE_BOTH);
        if ($metaOff !== []) {
            $items[] = $this->item(
                input: $input, category: AdvisorCategory::Landing, ruleId: 'meta-destination-offsite', keyParts: [], severity: 'low', impact: null,
                impactLabel: count($metaOff).' farklı hedef alan adı',
                title: 'Meta reklamları markanın sitesi dışındaki adreslere gidiyor',
                reason: sprintf('Kreatiflerin hedef adresleri: %s. Markanın sitesi %s. Piksel ve dönüşüm ölçümü bu adreslerde çalışmayabilir.', implode(', ', array_keys($metaOff)), implode(', ', $siteHosts)),
                evidence: ['hosts' => $metaOff, 'site_hosts' => $siteHosts],
                checklist: ['Kreatiflerin bağlantılarını kontrol et.', 'Bilinçli bir hedefse izinli alan adlarına ekle.'],
                copyText: null, baseline: null,
            );
        }

        return $items;
    }
}
