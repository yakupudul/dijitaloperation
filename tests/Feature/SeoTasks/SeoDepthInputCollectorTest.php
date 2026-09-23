<?php

namespace Tests\Feature\SeoTasks;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faz 2 collector: page traffic windows, latest URL inspection, sitemaps, the latest link graph,
 * lab speed and the brand's Business Profile snapshot — all from already collected tables.
 */
final class SeoDepthInputCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_depth_sources_are_read_from_stored_data(): void
    {
        $end = CarbonImmutable::parse('2026-09-23');
        $brand = Brand::factory()->create();
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'example.com', 'primary_url' => 'https://example.com/', 'module_id' => 'website']);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'dev']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'dev']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'atok', 'refresh_token' => 'rtok'], 'expires_at' => now()->addHour()]);
        $gsc = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'search_console', 'external_id' => 'sc-domain:example.com', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['site_url' => 'sc-domain:example.com']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console']);
        $common = fn (array $row): array => $row + ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', json_encode($row)), 'created_at' => now(), 'updated_at' => now()];

        foreach ([['2026-09-10', 3], ['2026-08-10', 10], ['2026-06-01', 1]] as [$date, $clicks]) {
            DB::table('gsc_page_daily')->insert($common(['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:example.com', 'reporting_date' => $date, 'page' => 'https://example.com/implant/', 'clicks' => $clicks, 'impressions' => $clicks * 10, 'search_type' => 'web', 'metadata' => '{}']));
        }
        foreach ([['2026-09-01 00:00:00', 'NEUTRAL'], ['2026-09-20 00:00:00', 'PASS']] as [$at, $verdict]) {
            DB::table('gsc_url_inspection_snapshot')->insert($common(['digital_asset_id' => $site->id, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:example.com', 'page' => 'https://example.com/implant/', 'inspected_at' => $at, 'metadata' => json_encode(['verdict' => $verdict, 'coverage_state' => 'x', 'google_canonical' => 'https://example.com/implant/', 'user_canonical' => 'https://example.com/implant/'])]));
        }
        DB::table('gsc_sitemap_snapshot')->insert($common(['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:example.com', 'sitemap_path' => 'https://example.com/sitemap.xml', 'retrieved_at' => '2026-09-20 00:00:00', 'metadata' => json_encode(['errors' => 3, 'warnings' => 1])]));
        foreach ([['2026-09-01 00:00:00', 'https://example.com/eski/'], ['2026-09-20 00:00:00', 'https://example.com/implant/'], ['2026-09-20 00:00:00', 'https://example.com/']] as [$at, $target]) {
            DB::table('website_link_edge')->insert($common(['digital_asset_id' => $site->id, 'external_resource_id' => null, 'edge_key' => hash('sha256', $at.$target), 'source_url' => 'https://example.com/', 'target_url' => $target, 'normalized_target_url' => $target, 'is_internal' => true, 'anchor_text' => 'x', 'observed_at' => $at, 'metadata' => '{}']));
        }
        DB::table('website_performance_measurement')->insert($common(['digital_asset_id' => $site->id, 'external_resource_id' => null, 'url' => 'https://example.com/implant/', 'observed_at' => '2026-09-20 00:00:00', 'strategy' => 'mobile', 'metadata' => json_encode(['lcp_ms' => 4321.6])]));
        $profileAsset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile']);
        $location = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/1']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $profileAsset->id, 'external_resource_id' => $location->id, 'capability' => 'google_business_profile']);
        DB::table('gbp_location_snapshots')->insert(['digital_asset_id' => null, 'external_resource_id' => $location->id, 'run_id' => 1, 'location_name' => 'locations/1', 'title' => 'Örnek Klinik', 'phone_numbers' => json_encode(['primaryPhone' => '+90 216 555 00 00']), 'website_uri' => 'https://example.com/', 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $input = app(SeoPlanInputCollector::class)->collect($site, $end);
        $implant = SeoText::urlKey('https://example.com/implant/');

        $traffic = $input['traffic']['pages'][$implant];
        $this->assertSame([3, 10, 30, 100, 130], [$traffic['clicks_cur'], $traffic['clicks_prev'], $traffic['impr_cur'], $traffic['impr_prev'], $traffic['impr_90']]);
        $this->assertGreaterThanOrEqual(100, $input['traffic']['history_days']);
        $this->assertSame('PASS', $input['inspections'][$implant]['verdict'], 'latest inspection wins');
        $this->assertSame(3, $input['sitemaps'][0]['errors']);
        $this->assertSame([SeoText::urlKey('https://example.com/')], $input['links']['inlinks'][$implant]);
        $this->assertArrayNotHasKey(SeoText::urlKey('https://example.com/eski/'), $input['links']['inlinks'], 'only the latest crawl of a page counts');
        $this->assertSame(4322, $input['performance'][$implant]['lcp_ms']);
        $this->assertSame(['2165550000'], $input['gbp']['phones']);
        $this->assertSame('Örnek Klinik', $input['gbp']['title']);
    }
}
