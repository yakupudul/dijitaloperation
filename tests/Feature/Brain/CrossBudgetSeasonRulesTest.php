<?php

namespace Tests\Feature\Brain;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\DigitalAsset;
use App\Services\Advisor\Cross\CrossChannelInputCollector;
use App\Services\Advisor\Cross\CrossChannelRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faz 14: cross-channel budget shift (Google Ads vs Meta cost per counted conversion) and season-ahead
 * (last year's organic clicks for the coming weeks).
 */
final class CrossBudgetSeasonRulesTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $extra */
    private function input(array $extra): array
    {
        return $extra + [
            'bound' => true,
            'asset' => ['id' => 1, 'name' => 'ornekklinik.com', 'brand_name' => 'Örnek Klinik'],
            'currency' => 'TRY',
            'period' => ['end' => '2026-09-20', 'days' => 90],
            'ads_terms' => [],
            'gbp_keywords' => [],
            'gsc_available' => false,
            'gsc_queries' => [],
            'pages' => [],
            'consistency' => null,
        ];
    }

    public function test_budget_shift_needs_both_channels_with_volume_and_a_clear_gap(): void
    {
        $engine = new CrossChannelRuleEngine;
        $spend = ['days' => 28, 'google_ads' => ['cost' => 10000.0, 'conversions' => 50.0], 'meta' => ['cost' => 6000.0, 'conversions' => 60.0]];
        $items = collect($engine->evaluate($this->input(['channel_spend' => $spend]))['items'])->keyBy('rule_id');

        $item = $items['budget-shift'];
        $this->assertStringStartsWith('Meta dönüşümü Google Ads', $item['title']);
        $this->assertEquals(1500.0, $item['impact_amount']);
        $this->assertSame('Meta', $item['evidence']['channels'][0]['channel']);

        // Close CPAs: no item.
        $close = ['google_ads' => ['cost' => 10000.0, 'conversions' => 50.0], 'meta' => ['cost' => 8000.0, 'conversions' => 50.0]] + $spend;
        $this->assertSame([], $engine->evaluate($this->input(['channel_spend' => $close]))['items']);
        // No counted Meta conversion: no CPA, no item.
        $noMeta = ['meta' => ['cost' => 6000.0, 'conversions' => null]] + $spend;
        $this->assertSame([], $engine->evaluate($this->input(['channel_spend' => $noMeta]))['items']);
        // Too few conversions on one side.
        $thin = ['meta' => ['cost' => 1500.0, 'conversions' => 4.0]] + $spend;
        $this->assertSame([], $engine->evaluate($this->input(['channel_spend' => $thin]))['items']);
    }

    public function test_season_ahead_fires_on_a_clear_rise_only(): void
    {
        $engine = new CrossChannelRuleEngine;
        $season = ['window_days' => 60, 'ahead_clicks' => 1400, 'before_clicks' => 1000, 'ahead_from' => '2026-09-24', 'ahead_to' => '2026-11-22'];
        $items = collect($engine->evaluate($this->input(['season' => $season]))['items'])->keyBy('rule_id');
        $this->assertTrue($items->has('season-ahead'));
        $this->assertStringContainsString('%40', $items['season-ahead']['impact_label']);

        $this->assertSame([], $engine->evaluate($this->input(['season' => ['ahead_clicks' => 1100] + $season]))['items']);
        $this->assertSame([], $engine->evaluate($this->input(['season' => ['ahead_clicks' => 150, 'before_clicks' => 100] + $season]))['items']);
    }

    public function test_collector_reads_channel_spend_and_last_years_season(): void
    {
        $brand = Brand::factory()->create();
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'ornekklinik.com', 'status' => 'active']);
        $ads = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => 'active']);
        $meta = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'status' => 'active']);
        $common = ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('a', 64)];
        for ($d = 1; $d <= 10; $d++) {
            $date = now()->subDays($d)->toDateString();
            DB::table('google_ads_campaign_daily')->insert($common + ['digital_asset_id' => $ads->id, 'customer_id' => '1', 'reporting_date' => $date, 'campaign_id' => 'c1', 'cost_micros' => 0, 'cost_amount' => 100, 'conversions' => 2, 'currency' => 'TRY']);
            DB::table('meta_campaign_daily')->insert($common + ['digital_asset_id' => $meta->id, 'account_id' => 'act_1', 'reporting_date' => $date, 'campaign_id' => 'm1', 'spend' => 50]);
            DB::table('meta_typed_action_daily')->insert($common + ['digital_asset_id' => $meta->id, 'account_id' => 'act_1', 'reporting_date' => $date, 'entity_level' => 'account', 'entity_id' => 'act_1', 'action_type' => 'lead', 'action_value' => 3]);
        }
        BrandConversionSource::query()->create(['brand_id' => $brand->id, 'source' => BrandConversionSource::SOURCE_META, 'source_key' => 'lead', 'label' => 'Form', 'conversion_type' => 'lead', 'counts' => true]);
        $pivot = now()->startOfDay()->subYear();
        foreach ([[-5, 10], [5, 20]] as [$offset, $clicks]) {
            DB::table('gsc_property_daily')->insert($common + ['digital_asset_id' => $site->id, 'site_url' => 'sc-domain:ornekklinik.com', 'reporting_date' => $pivot->copy()->addDays($offset)->toDateString(), 'clicks' => $clicks, 'impressions' => 100]);
        }

        $input = app(CrossChannelInputCollector::class)->collect($site->fresh());

        $this->assertTrue($input['bound']);
        $this->assertEquals(['cost' => 1000.0, 'conversions' => 20.0], $input['channel_spend']['google_ads']);
        $this->assertEquals(['cost' => 500.0, 'conversions' => 30.0], $input['channel_spend']['meta']);
        $this->assertSame(20, $input['season']['ahead_clicks']);
        $this->assertSame(10, $input['season']['before_clicks']);
    }
}
