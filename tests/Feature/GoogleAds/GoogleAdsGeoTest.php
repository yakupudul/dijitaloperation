<?php

namespace Tests\Feature\GoogleAds;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalGaqlBuilder;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalNormalizer;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProfessionalRequestFamilyCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Province / district performance: GAQL and normalization (the screen read is covered by GoogleAdsScreenTest). */
final class GoogleAdsGeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_rows_are_normalized(): void
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
    }
}
