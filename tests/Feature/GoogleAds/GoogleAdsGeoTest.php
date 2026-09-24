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

/** Province / district performance: GAQL, normalization and the named read. */
final class GoogleAdsGeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_rows_are_normalized_and_read_with_place_names(): void
    {
        $gaql = app(GoogleAdsProfessionalGaqlBuilder::class);
        $this->assertStringContainsString('FROM geographic_view', $gaql->query(GoogleAdsProfessionalRequestFamilyCatalog::GEO_DAILY, '2026-09-01', '2026-09-02'));
        $this->assertStringNotContainsString("'1; DROP", $gaql->geoTargetNames(['geoTargetConstants/1012782', '1; DROP']));

        $records = app(GoogleAdsProfessionalNormalizer::class)->normalize(GoogleAdsProfessionalRequestFamilyCatalog::GEO_DAILY, [[
            'segments' => ['date' => '2026-09-01', 'geoTargetRegion' => 'geoTargetConstants/21150', 'geoTargetCity' => 'geoTargetConstants/1012782'],
            'geographicView' => ['locationType' => 'LOCATION_OF_PRESENCE'],
            'metrics' => ['clicks' => 10, 'impressions' => 100, 'costMicros' => 50_000_000, 'conversions' => 2],
        ]], '1112223333', 'Europe/Istanbul', 'TRY', 1, 9);
        $this->assertSame('geoTargetConstants/21150', $records[0]['geo_target_region']);
        $this->assertSame('LOCATION_OF_PRESENCE', $records[0]['location_type']);

        foreach (['LOCATION_OF_PRESENCE' => 50, 'AREA_OF_INTEREST' => 999] as $type => $cost) {
            DB::table('google_ads_geo_daily')->insert([
                'external_resource_id' => 9, 'customer_id' => '1112223333', 'reporting_date' => '2026-09-01', 'location_type' => $type,
                'geo_target_region' => 'geoTargetConstants/21150', 'geo_target_city' => 'geoTargetConstants/1012782', 'clicks' => 10, 'impressions' => 100,
                'cost_amount' => $cost, 'conversions' => 2, 'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => str_repeat($type[0], 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('google_ads_geo_names')->insert(['resource_name' => 'geoTargetConstants/21150', 'name' => 'Manisa', 'created_at' => now(), 'updated_at' => now()]);

        $geo = new ReflectionMethod(GoogleAdsProfessionalWorkspaceReadService::class, 'geo');
        $regions = $geo->invoke(app(GoogleAdsProfessionalWorkspaceReadService::class), 'geo_target_region', 9, '1112223333', '2026-09-01', '2026-09-30');
        $cities = $geo->invoke(app(GoogleAdsProfessionalWorkspaceReadService::class), 'geo_target_city', 9, '1112223333', '2026-09-01', '2026-09-30');

        $this->assertSame('Manisa', $regions[0]['label']);
        $this->assertSame(50.0, $regions[0]['cost_amount'], 'area-of-interest rows are not double counted');
        $this->assertSame(25.0, $regions[0]['cpa']);
        $this->assertSame('Konum #1012782', $cities[0]['label']);
    }
}
