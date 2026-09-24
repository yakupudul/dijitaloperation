<?php

namespace Tests\Unit;

use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorRuleEngine;
use Tests\TestCase;

/** Device / province / hour waste and negatives that block the account's own keywords. */
final class GoogleAdsSegmentAndConflictRulesTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function rule(string $rule, array $input): array
    {
        $engine = app(GoogleAdsAdvisorRuleEngine::class);
        (new \ReflectionProperty($engine, 'cfg'))->setValue($engine, (array) config('moxdop-advisor.google_ads', []));

        return (new \ReflectionMethod($engine, $rule))->invoke($engine, $input);
    }

    /** @return array<string, mixed> */
    private function input(array $extra): array
    {
        return $extra + [
            'bound' => true, 'currency' => 'TRY', 'asset' => ['brand_name' => 'Örnek'],
            'account' => ['has_data' => true, 'cost' => 10000.0, 'clicks' => 1000, 'impressions' => 20000, 'conversions' => 50.0, 'conversions_value' => 0.0, 'cpa' => 200.0, 'auto_tagging_enabled' => true, 'name' => 'Örnek'],
            'period' => ['start' => '2026-08-25', 'end' => '2026-09-23', 'days' => 30], 'conversion_actions' => [], 'ads' => [], 'landing_pages' => [],
            'asset_library' => [], 'recommendations' => [], 'changes' => [], 'targets' => [], 'offerings' => [], 'website' => [], 'ga4' => [], 'quality_history' => [],
            'campaigns' => [], 'campaign_daily' => [], 'search_terms' => [], 'negatives' => [], 'keywords' => [], 'segments' => [],
        ];
    }

    public function test_expensive_segments_become_one_item_per_dimension(): void
    {
        $result = $this->rule('segmentWaste', $this->input(['segments' => [
            'device' => [['label' => 'MOBILE', 'cost' => 7000.0, 'clicks' => 700, 'conversions' => 45.0], ['label' => 'TABLET', 'cost' => 1500.0, 'clicks' => 100, 'conversions' => 0.0]],
            'region' => [['label' => 'İzmir', 'cost' => 2000.0, 'clicks' => 200, 'conversions' => 2.0], ['label' => 'Manisa', 'cost' => 3000.0, 'clicks' => 300, 'conversions' => 30.0]],
            'hour' => [],
        ]]));
        $items = collect($result)->where('rule_id', 'segment-bid-adjustment')->values();

        $this->assertCount(2, $items);
        $tablet = $items->first(fn (array $i): bool => $i['evidence']['dimension'] === 'device');
        $this->assertSame('TABLET', $tablet['evidence']['rows'][0]['segment']);
        $izmir = $items->first(fn (array $i): bool => $i['evidence']['dimension'] === 'region');
        $this->assertSame(1600.0, $izmir['evidence']['rows'][0]['excess'], '2000 spent vs 2 × 200 expected');
    }

    public function test_negative_that_blocks_an_enabled_keyword_is_flagged_in_scope_only(): void
    {
        $result = $this->rule('negativeConflicts', $this->input([
            'keywords' => [
                ['text' => 'implant fiyatları', 'status' => 'ENABLED', 'campaign_id' => 'c1', 'ad_group_id' => 'ag1'],
                ['text' => 'zirkonyum fiyatları', 'status' => 'ENABLED', 'campaign_id' => 'c2', 'ad_group_id' => 'ag2'],
            ],
            'negatives' => [
                ['text' => 'fiyatları', 'match_type' => 'BROAD', 'level' => 'campaign', 'campaign_id' => 'c1', 'ad_group_id' => null],
            ],
        ]));
        $item = collect($result)->firstWhere('rule_id', 'negative-keyword-conflict');

        $this->assertNotNull($item);
        $this->assertSame(['implant fiyatları'], array_column($item['evidence']['rows'], 'keyword'));
    }
}
