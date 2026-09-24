<?php

namespace Tests\Feature\GoogleAds;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalGaqlBuilder;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalNormalizer;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalRequestFamilyCatalog;
use App\Services\GoogleAds\GoogleAdsProfessionalWorkspaceReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** Performance Max assets, Shopping products and videos are shown by name, not provider id. */
final class GoogleAdsReadableNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pmax_shopping_and_video_rows_get_names_from_metadata(): void
    {
        $this->assertStringContainsString('asset.text_asset.text', app(GoogleAdsProfessionalGaqlBuilder::class)->query(GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_DAILY, '2026-09-01', '2026-09-02'));
        $records = app(GoogleAdsProfessionalNormalizer::class)->normalize(GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_DAILY, [[
            'segments' => ['date' => '2026-09-01'], 'campaign' => ['id' => '5', 'name' => 'PMax Genel'], 'assetGroup' => ['id' => '6', 'name' => 'İmplant'],
            'assetGroupAsset' => ['asset' => 'customers/1/assets/77', 'fieldType' => 'HEADLINE'],
            'asset' => ['type' => 'TEXT', 'textAsset' => ['text' => 'Ağrısız implant tedavisi']],
            'metrics' => ['clicks' => 3, 'impressions' => 30, 'costMicros' => 9_000_000],
        ]], '1112223333', 'Europe/Istanbul', 'TRY', 1, 9);
        $this->assertSame('Ağrısız implant tedavisi', $records[0]['metadata']['asset_text']);

        $base = ['external_resource_id' => 9, 'customer_id' => '1112223333', 'reporting_date' => '2026-09-01', 'clicks' => 3, 'impressions' => 30,
            'cost_amount' => 9, 'conversions' => 1, 'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];
        DB::table('google_ads_pmax_asset_daily')->insert($base + ['campaign_id' => '5', 'asset_group_id' => '6', 'asset_id' => '77', 'field_type' => 'HEADLINE',
            'metadata' => json_encode(['asset_text' => 'Ağrısız implant tedavisi', 'campaign_name' => 'PMax Genel', 'asset_group_name' => 'İmplant'])]);
        DB::table('google_ads_shopping_product_daily')->insert($base + ['product_key' => str_repeat('f', 64),
            'metadata' => json_encode(['title' => 'Beyazlatma kiti', 'item_id' => 'SKU-1', 'brand' => 'Atlas'])]);
        DB::table('google_ads_video_daily')->insert($base + ['video_id' => 'abc123', 'ad_format_type' => 'IN_STREAM', 'video_views' => 40,
            'metadata' => json_encode(['title' => 'Klinik tanıtım'])]);

        $service = app(GoogleAdsProfessionalWorkspaceReadService::class);
        $breakdown = new ReflectionMethod($service, 'dailyBreakdown');
        $names = new ReflectionMethod($service, 'withNames');
        $video = new ReflectionMethod($service, 'videoBreakdown');

        $pmax = $names->invoke($service, $breakdown->invoke($service, 'google_ads_pmax_asset_daily', ['campaign_id', 'asset_group_id', 'asset_id', 'field_type'], 9, '1112223333', '2026-09-01', '2026-09-30', 100),
            'google_ads_pmax_asset_daily', 'asset_id', 9, '1112223333', '2026-09-01', '2026-09-30',
            static fn (array $m): ?string => $m['asset_text'] ?? null, static fn (array $m): array => ['asset_group_name' => $m['asset_group_name'] ?? null]);
        $this->assertSame('Ağrısız implant tedavisi', $pmax[0]['name']);
        $this->assertSame('İmplant', $pmax[0]['asset_group_name']);

        $shopping = $names->invoke($service, $breakdown->invoke($service, 'google_ads_shopping_product_daily', ['product_key'], 9, '1112223333', '2026-09-01', '2026-09-30', 100),
            'google_ads_shopping_product_daily', 'product_key', 9, '1112223333', '2026-09-01', '2026-09-30',
            static fn (array $m): ?string => $m['title'] ?? null, static fn (array $m): array => ['item_id' => $m['item_id'] ?? null]);
        $this->assertSame('Beyazlatma kiti', $shopping[0]['name']);
        $this->assertSame('SKU-1', $shopping[0]['item_id']);

        $videos = $names->invoke($service, $video->invoke($service, 9, '1112223333', '2026-09-01', '2026-09-30'),
            'google_ads_video_daily', 'video_id', 9, '1112223333', '2026-09-01', '2026-09-30', static fn (array $m): ?string => $m['title'] ?? null, static fn (array $m): array => []);
        $this->assertSame('Klinik tanıtım', $videos[0]['name']);
    }
}
