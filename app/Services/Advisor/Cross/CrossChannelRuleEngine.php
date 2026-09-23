<?php

namespace App\Services\Advisor\Cross;

use App\Enums\AdvisorCategory;
use App\Services\Advisor\Support\BuildsAdvisorItems;
use App\Services\SeoTasks\SeoText;

/**
 * Cross-channel rules (pure): what one channel knows that another should act on.
 *  - A search term converts in Google Ads but the site has no page for it and ranks poorly organically.
 *  - The brand pays for its own name while it already ranks first organically (a test, not a verdict).
 *  - People find the Business Profile with a search the website has no content for.
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
}
