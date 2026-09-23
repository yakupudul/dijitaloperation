<?php

namespace Tests\Feature\Advisor;

use App\Services\Advisor\GoogleAds\GoogleAdsAdCopyDrafter;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorRuleEngine;
use Tests\TestCase;

/**
 * Pure Google Ads advisor rules over an in-memory collector package (no database, no AI).
 */
final class GoogleAdsAdvisorRuleEngineTest extends TestCase
{
    public function test_every_rule_fires_on_its_evidence(): void
    {
        $result = (new GoogleAdsAdvisorRuleEngine)->evaluate($this->input());
        config(['moxdop-advisor.google_ads.max_open' => 50]);
        $items = collect((new GoogleAdsAdvisorRuleEngine)->evaluate($this->input())['items'])->keyBy('rule_id');

        foreach (['negative-keywords', 'keyword-opportunities', 'budget-limited-profitable', 'budget-waste', 'conversion-settings', 'ga4-mismatch', 'landing-page-issues', 'weak-ad-strength', 'missing-assets', 'google-recommendations', 'low-quality-score', 'change-impact'] as $rule) {
            $this->assertTrue($items->has($rule), $rule.' should fire');
        }
        $this->assertLessThanOrEqual(6, count(array_filter($result['items'], fn (array $i): bool => $i['severity'] !== 'critical')), 'default quota keeps few items');

        // Negatives: existing negatives, brand terms and service-named terms are not in the paste list.
        $negatives = $items['negative-keywords'];
        $terms = array_column($negatives['evidence']['terms'], 'term');
        $this->assertContains('ücretsiz diş tedavisi', $terms);
        $this->assertContains('diş hekimi iş ilanları', $terms);
        $this->assertNotContains('diş hekimi maaşları', $terms, 'already an exact negative');
        $this->assertNotContains('örnek klinik yorumları', $terms, 'brand term');
        $this->assertNotContains('implant fiyatları', $terms, 'names a service: listed for review, not as negative');
        $this->assertSame('implant fiyatları', $negatives['evidence']['service_terms'][0]['term']);
        $this->assertStringContainsString('[ücretsiz diş tedavisi]', $negatives['copy_text']);
        $this->assertContains('ücretsiz', array_column($negatives['evidence']['words'], 'word'));
        $this->assertStringContainsString('"ücretsiz"', $negatives['copy_text']);

        // Converting term that is not a keyword yet.
        $this->assertSame(['[zirkonyum kaplama fiyat]'], explode("\n", $items['keyword-opportunities']['copy_text']));

        $this->assertSame('Implant Arama', $items['budget-limited-profitable']['evidence']['campaigns'][0]['name']);
        $this->assertSame('Display Deneme', $items['budget-waste']['evidence']['campaigns'][0]['name']);
        $this->assertCount(2, $items['conversion-settings']['evidence']['issues']);
        $this->assertSame('critical', $items['landing-page-issues']['severity'], 'a 404 landing page is critical');
        $this->assertSame('Implant Genel', $items['weak-ad-strength']['evidence']['ad_group']);
        $this->assertSame(['Site bağlantısı ekle'], array_column($items['google-recommendations']['evidence']['recommendations'], 'label'), 'budget and broad-match nudges are filtered out');
        $this->assertSame('implant fiyat', $items['low-quality-score']['evidence']['keywords'][0]['keyword']);
        $this->assertSame('2026-09-05', $items['change-impact']['evidence']['date']);
    }

    public function test_measurement_blockers_and_silence(): void
    {
        $input = $this->input();
        $input['conversion_actions']['items'] = [['id' => '1', 'name' => 'Sayfa', 'status' => 'ENABLED', 'category' => 'PAGE_VIEW', 'type' => 'WEBPAGE', 'origin' => 'WEBSITE', 'primary' => false, 'counting_type' => 'ONE_PER_CLICK', 'conversions' => 10.0]];
        $input['account']['auto_tagging_enabled'] = false;
        config(['moxdop-advisor.google_ads.max_open' => 50]);
        $items = collect((new GoogleAdsAdvisorRuleEngine)->evaluate($input)['items'])->keyBy('rule_id');
        $this->assertSame('critical', $items['no-primary-conversion']['severity']);
        $this->assertTrue($items->has('auto-tagging-off'));

        $engine = new GoogleAdsAdvisorRuleEngine;
        $this->assertSame(['not_bound'], $engine->evaluate(['bound' => false])['silenced']);
        $low = $this->input();
        foreach ($low['campaigns'] as &$campaign) {
            $campaign['cost'] = 1.0;
        }
        unset($campaign);
        $low['account']['cost'] = 10.0;
        $this->assertSame([], $engine->evaluate($low)['items'], 'low spend: no advice');

        $empty = $this->input();
        foreach (['search_terms', 'keywords', 'landing_pages', 'negatives'] as $key) {
            $empty[$key] = [];
        }
        $empty['ads'] = ['available' => false, 'items' => [], 'ad_groups' => [], 'metrics_available' => false];
        $empty['asset_library'] = ['available' => false, 'counts' => []];
        $empty['recommendations'] = ['available' => false, 'observed_date' => null, 'items' => []];
        $empty['changes'] = ['available' => false, 'items' => []];
        $empty['ga4'] = ['available' => false, 'sessions' => null, 'key_events' => null];
        $rules = array_column((new GoogleAdsAdvisorRuleEngine)->evaluate($empty)['items'], 'rule_id');
        foreach (['negative-keywords', 'keyword-opportunities', 'landing-page-issues', 'weak-ad-strength', 'missing-assets', 'google-recommendations', 'low-quality-score', 'change-impact', 'ga4-mismatch'] as $rule) {
            $this->assertNotContains($rule, $rules, $rule.' must stay silent without its data');
        }
    }

    public function test_negative_coverage_semantics(): void
    {
        $engine = new GoogleAdsAdvisorRuleEngine;
        $this->assertTrue($engine->coveredByNegative('diş hekimi maaşları', [['text' => 'diş hekimi maaşları', 'match_type' => 'EXACT']]));
        $this->assertFalse($engine->coveredByNegative('diş hekimi maaşları 2026', [['text' => 'diş hekimi maaşları', 'match_type' => 'EXACT']]));
        $this->assertTrue($engine->coveredByNegative('ankara iş ilanı diş', [['text' => 'iş ilanı', 'match_type' => 'PHRASE']]));
        $this->assertTrue($engine->coveredByNegative('staj yeri diş kliniği', [['text' => 'klinik staj', 'match_type' => 'BROAD']]) === false);
        $this->assertTrue($engine->coveredByNegative('diş kliniği staj', [['text' => 'staj kliniği', 'match_type' => 'BROAD']]));
    }

    public function test_ad_copy_draft_respects_google_limits(): void
    {
        $draft = app(GoogleAdsAdCopyDrafter::class)->validate([
            'headlines' => ['İmplant Tedavisi', 'İmplant Tedavisi', str_repeat('x', 31), 'Ücretsiz Muayene'],
            'descriptions' => ['Kısa açıklama. Hemen randevu al.', str_repeat('y', 91)],
            'path1' => 'Diş İmplantı Tedavisi Ankara',
            'path2' => 'fiyat',
            'notes' => 'Kontrol et.',
        ]);
        $this->assertSame(['İmplant Tedavisi', 'Ücretsiz Muayene'], $draft['headlines']);
        $this->assertSame(['Kısa açıklama. Hemen randevu al.'], $draft['descriptions']);
        $this->assertLessThanOrEqual(15, mb_strlen($draft['path1']));
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $term = fn (string $text, float $cost, int $clicks, float $conv, array $statuses = ['NONE'], array $adGroups = ['ag1']): array => [
            'term' => $text, 'cost' => $cost, 'clicks' => $clicks, 'impressions' => $clicks * 10, 'conversions' => $conv,
            'statuses' => $statuses, 'campaign_ids' => ['c1'], 'ad_group_ids' => $adGroups, 'pmax' => false,
        ];
        $terms = [];
        foreach ([
            $term('ücretsiz diş tedavisi', 180, 12, 0),
            $term('ücretsiz implant', 90, 6, 0),
            $term('ücretsiz diş muayenesi', 60, 5, 0),
            $term('diş hekimi iş ilanları', 120, 9, 0),
            $term('diş hekimi maaşları', 140, 10, 0),
            $term('örnek klinik yorumları', 150, 20, 0),
            $term('implant fiyatları', 200, 15, 0),
            $term('implant diş', 900, 60, 20, ['ADDED']),
            $term('zirkonyum kaplama fiyat', 150, 12, 4),
            $term('eski terim', 300, 30, 0, ['EXCLUDED']),
        ] as $row) {
            $terms[mb_strtolower($row['term'])] = $row;
        }
        $daily = [];
        for ($d = strtotime('2026-08-25'); $d <= strtotime('2026-09-22'); $d += 86400) {
            $date = date('Y-m-d', $d);
            $after = $date > '2026-09-05';
            $daily['c1'][$date] = ['cost' => 100.0, 'clicks' => 20, 'conversions' => $after ? 1.0 : 3.0];
        }
        $campaign = fn (string $id, string $name, string $channel, float $cost, float $conv, ?float $lost): array => [
            'id' => $id, 'name' => $name, 'status' => 'ENABLED', 'channel' => $channel, 'budget_amount' => 100.0,
            'cost' => $cost, 'clicks' => (int) ($cost / 5), 'impressions' => (int) ($cost * 20), 'conversions' => $conv, 'conversions_value' => 0.0,
            'lost_is_budget' => $lost, 'lost_is_rank' => 0.1, 'cpa' => $conv > 0 ? $cost / $conv : null,
        ];
        $campaigns = [
            'c1' => $campaign('c1', 'Implant Arama', 'SEARCH', 3000, 60, 0.35),
            'c2' => $campaign('c2', 'Display Deneme', 'DISPLAY', 900, 0, null),
            'c3' => $campaign('c3', 'Marka', 'SEARCH', 500, 20, 0.02),
        ];

        return [
            'asset' => ['id' => 1, 'name' => 'Örnek Klinik Ads', 'brand_id' => 1, 'customer_id' => 1, 'brand_name' => 'Örnek Klinik'],
            'bound' => true,
            'binding_reason' => null,
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'period' => ['start' => '2026-08-24', 'end' => '2026-09-22', 'days' => 30],
            'account' => ['cost' => 4400.0, 'clicks' => 880, 'impressions' => 88000, 'conversions' => 80.0, 'conversions_value' => 0.0, 'name' => 'Örnek', 'auto_tagging_enabled' => true, 'cpa' => 55.0, 'has_data' => true],
            'campaigns' => $campaigns,
            'campaign_daily' => $daily,
            'search_terms' => $terms,
            'negatives' => [['text' => 'diş hekimi maaşları', 'match_type' => 'EXACT', 'level' => 'campaign', 'campaign_id' => 'c1', 'ad_group_id' => null]],
            'keywords' => [
                ['ad_group_id' => 'ag1', 'criterion_id' => 'k1', 'campaign_id' => 'c1', 'text' => 'implant fiyat', 'match_type' => 'PHRASE', 'status' => 'ENABLED', 'quality_score' => 3, 'ad_relevance' => 'AVERAGE', 'landing_page_experience' => 'BELOW_AVERAGE', 'expected_ctr' => 'AVERAGE', 'cost' => 400.0, 'clicks' => 60, 'impressions' => 900, 'conversions' => 2.0],
                ['ad_group_id' => 'ag1', 'criterion_id' => 'k2', 'campaign_id' => 'c1', 'text' => 'implant diş', 'match_type' => 'EXACT', 'status' => 'ENABLED', 'quality_score' => 8, 'ad_relevance' => 'ABOVE_AVERAGE', 'landing_page_experience' => 'AVERAGE', 'expected_ctr' => 'AVERAGE', 'cost' => 900.0, 'clicks' => 60, 'impressions' => 900, 'conversions' => 20.0],
            ],
            'ads' => [
                'available' => true, 'metrics_available' => true, 'ad_groups' => ['ag1' => 'Implant Genel'],
                'items' => [['ad_id' => 'a1', 'type' => 'RESPONSIVE_SEARCH_AD', 'status' => 'ENABLED', 'ad_strength' => 'POOR', 'final_urls' => ['https://ornek.test/implant/'], 'ad_group_id' => 'ag1', 'campaign_id' => 'c1', 'metrics' => ['cost' => 1500.0, 'clicks' => 200, 'conversions' => 30.0]]],
            ],
            'landing_pages' => [
                'https://ornek.test/implant/' => ['url' => 'https://ornek.test/implant/', 'cost' => 2500.0, 'clicks' => 500, 'conversions' => 50.0, 'speed_score' => 3, 'mobile_friendly' => 100.0],
                'https://ornek.test/eski-kampanya/' => ['url' => 'https://ornek.test/eski-kampanya/', 'cost' => 400.0, 'clicks' => 80, 'conversions' => 0.0, 'speed_score' => 7, 'mobile_friendly' => 100.0],
            ],
            'conversion_actions' => ['available' => true, 'daily_available' => true, 'items' => [
                ['id' => '1', 'name' => 'Form', 'status' => 'ENABLED', 'category' => 'SUBMIT_LEAD_FORM', 'type' => 'WEBPAGE', 'origin' => 'WEBSITE', 'primary' => true, 'counting_type' => 'MANY_PER_CLICK', 'conversions' => 60.0],
                ['id' => '2', 'name' => 'Sayfa görüntüleme', 'status' => 'ENABLED', 'category' => 'PAGE_VIEW', 'type' => 'WEBPAGE', 'origin' => 'WEBSITE', 'primary' => true, 'counting_type' => 'ONE_PER_CLICK', 'conversions' => 20.0],
            ]],
            'asset_library' => ['available' => true, 'counts' => ['SITELINK' => 2, 'CALLOUT' => 5]],
            'recommendations' => ['available' => true, 'observed_date' => '2026-09-22', 'items' => [
                ['type' => 'CAMPAIGN_BUDGET', 'campaign_id' => 'c1', 'impact' => null],
                ['type' => 'USE_BROAD_MATCH_KEYWORD', 'campaign_id' => 'c1', 'impact' => null],
                ['type' => 'SITELINK_ASSET', 'campaign_id' => 'c1', 'impact' => null],
            ]],
            'changes' => ['available' => true, 'items' => [
                ['changed_at' => '2026-09-05 10:00:00', 'date' => '2026-09-05', 'resource_type' => 'CAMPAIGN', 'operation' => 'UPDATE', 'client_type' => 'GOOGLE_ADS_WEB_CLIENT', 'user' => 'ops@example.test', 'campaign_id' => 'c1', 'changed_fields' => 'maximize_conversions.target_cpa_micros'],
            ]],
            'targets' => ['target_cpa' => null, 'target_roas' => null],
            'offerings' => [['id' => 1, 'name' => 'İmplant', 'names' => ['İmplant'], 'keywords' => ['implant'], 'is_priority' => true, 'priority_rank' => 1, 'queries' => []]],
            'website' => ['available' => true, 'asset_id' => 2, 'domain' => 'ornekklinik.com', 'pages' => [
                'ornek.test/eski-kampanya' => ['url' => 'https://ornek.test/eski-kampanya/', 'status_code' => 404, 'final_url' => null, 'noindex' => false, 'title' => null, 'h1' => null, 'meta_description' => null],
            ]],
            'ga4' => ['available' => true, 'sessions' => 700, 'key_events' => 20.0, 'website_asset_id' => 2],
        ];
    }
}
