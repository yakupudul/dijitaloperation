<?php

namespace Tests\Feature\Mcp;

use App\Enums\DigitalAssetStatus;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\ChannelData;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** channel-data: fixed read-only aggregations of a brand's Google Ads, Search Console, GA4 and Business Profile data. */
final class McpChannelDataTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $ads;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Estetik']);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active]);
    }

    public function test_google_ads_campaigns_are_summed_named_and_scoped_to_the_brand(): void
    {
        $other = DigitalAsset::factory()->create(['brand_id' => Brand::factory()->create(['customer_id' => Customer::factory()->create()->id])->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $this->campaignDay($this->ads->id, '2026-10-01', 'c1', 150.5, 3);
        $this->campaignDay($this->ads->id, '2026-10-02', 'c1', 49.5, 1);
        $this->campaignDay($this->ads->id, '2026-10-02', 'c2', 20, 0);
        $this->campaignDay($other->id, '2026-10-02', 'c9', 9999, 50);
        DB::table('google_ads_campaign_snapshot')->insert($this->provenance($this->ads->id) + ['customer_id' => '1', 'campaign_id' => 'c1', 'metadata' => json_encode(['name' => 'Yurtdışı Arama', 'status' => 'ENABLED'])]);

        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'google_ads.campaigns'])->assertOk()
            ->assertSee('"name":"Yurtdışı Arama"')
            ->assertSee('"cost":200')
            ->assertSee('"conversions":4')
            ->assertSee('"last_day":"2026-10-02"')
            ->assertDontSee('c9');

        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'google_ads.months'])->assertOk()
            ->assertSee('"month":"2026-10"')->assertSee('"cost":220');
    }

    public function test_gsc_counts_web_search_only_and_ga4_and_reviews_group(): void
    {
        foreach ([['web', 'de', 40, 'https://x.example/'], ['image', 'de', 500, 'sc-domain:x.example'], ['web', 'tur', 10, 'https://x.example/']] as [$type, $country, $clicks, $property]) {
            DB::table('gsc_country_daily')->insert($this->provenance($this->site->id) + ['site_url' => $property, 'reporting_date' => '2026-10-01', 'country' => $country, 'clicks' => $clicks, 'impressions' => $clicks * 10, 'search_type' => $type]);
        }
        DB::table('ga4_source_medium_daily')->insert($this->provenance($this->site->id) + ['property_id' => 'p1', 'reporting_date' => '2026-10-01', 'sessionSource' => 'google', 'sessionMedium' => 'organic', 'sessions' => 70, 'engagedSessions' => 40, 'totalUsers' => 60, 'keyEvents' => 3]);
        DB::table('gbp_reviews')->insert(['digital_asset_id' => $this->site->id, 'external_resource_id' => 1, 'run_id' => 1, 'raw_payload' => '{}', 'location_name' => 'locations/1', 'review_id' => 'r1', 'star_rating' => 'FIVE', 'create_time' => '2026-09-20 10:00:00', 'collected_at' => now()]);

        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'gsc.countries'])->assertOk()
            ->assertSee('"country":"de","clicks":40')->assertDontSee('"clicks":540');
        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'ga4.channels'])->assertOk()
            ->assertSee('"sessionSource":"google"')->assertSee('"sessions":70');
        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'gbp.reviews'])->assertOk()
            ->assertSee('"star_rating":"FIVE","count":1');
    }

    public function test_resource_first_rows_count_for_the_brand_without_adding_older_asset_rows(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1234567890', 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->ads->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->campaignDay(null, '2026-10-03', 'c1', 300, 6, $resource->id);
        $this->campaignDay($this->ads->id, '2026-10-01', 'c1', 999, 9, $resource->id);
        DB::table('google_ads_campaign_snapshot')->insert($this->provenance(null) + ['external_resource_id' => $resource->id, 'customer_id' => '1', 'campaign_id' => 'c1', 'metadata' => json_encode(['name' => 'All-on-4 UK', 'status' => 'ENABLED'])]);

        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'google_ads.campaigns'])->assertOk()
            ->assertSee('"name":"All-on-4 UK"')
            ->assertSee('"cost":300')
            ->assertSee('"last_day":"2026-10-03"')
            ->assertDontSee('"cost":1299');
    }

    public function test_old_central_rows_outside_the_period_do_not_hide_recent_asset_rows_and_empty_answers_say_why(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1234567890', 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->ads->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->campaignDay(null, '2025-01-03', 'c1', 300, 6, $resource->id);
        $this->campaignDay($this->ads->id, '2026-10-01', 'c1', 120, 2, $resource->id);

        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'google_ads.campaigns'])->assertOk()
            ->assertSee('"cost":120')->assertSee('"last_day":"2026-10-01"');
        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'google_ads.campaigns', 'contains' => 'yok'])->assertOk()
            ->assertSee('"sources"')->assertSee('"central_last_day":"2025-01-03"');
    }

    public function test_unknown_brand_or_dataset_is_refused(): void
    {
        MoxdopServer::tool(ChannelData::class, ['brand_id' => 999999, 'dataset' => 'gsc.queries'])->assertHasErrors();
        MoxdopServer::tool(ChannelData::class, ['brand_id' => $this->brand->id, 'dataset' => 'users'])->assertHasErrors();
    }

    private function campaignDay(?int $assetId, string $date, string $campaign, float $cost, int $conversions, ?int $resourceId = null): void
    {
        DB::table('google_ads_campaign_daily')->insert($this->provenance($assetId) + ['external_resource_id' => $resourceId] + [
            'customer_id' => '1', 'reporting_date' => $date, 'campaign_id' => $campaign, 'impressions' => 1000, 'clicks' => 50,
            'cost_micros' => (int) ($cost * 1000000), 'cost_amount' => $cost, 'conversions' => $conversions, 'currency' => 'TRY',
        ]);
    }

    /** @return array<string, mixed> */
    private function provenance(?int $assetId): array
    {
        return ['digital_asset_id' => $assetId, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];
    }
}
