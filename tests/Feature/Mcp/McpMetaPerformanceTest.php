<?php

namespace Tests\Feature\Mcp;

use App\Enums\DigitalAssetStatus;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\MetaPerformance;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Integrations\Meta\MetaResourceType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** meta-performance: a brand's stored Meta results grouped for Claude, result types kept apart, other brands never mixed in. */
final class McpMetaPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Atlas Estetik']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Atlas Meta']);
        $integration = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT, 'external_id' => 'act_777',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    public function test_it_groups_the_brands_results_and_keeps_result_types_apart(): void
    {
        $this->geo($this->asset->id, '2026-09-10', 'DE', 'Almanya Kampanya', 400, 6, 4);
        $this->geo($this->asset->id, '2026-09-11', 'TR', 'Türkiye Kampanya', 100, 2, 0);
        $this->geo($this->asset->id, '2026-10-05', 'DE', 'Almanya Kampanya', 200, 0, 5);
        $this->geo($this->asset->id, '2026-05-01', 'DE', 'Eski Kampanya', 999, 1, 0);
        $other = DigitalAsset::factory()->create(['brand_id' => Brand::factory()->create(['customer_id' => Customer::factory()->create()->id])->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active]);
        $this->geo($other->id, '2026-09-10', 'DE', 'Başka marka', 5000, 50, 0);
        DB::table('meta_breakdown_results_daily')->insert([
            'digital_asset_id' => $this->asset->id, 'account_id' => '777', 'reporting_date' => '2026-09-10', 'dimension' => 'age_gender',
            'ad_id' => 'a1', 'key1' => '35-44', 'key2' => 'female', 'spend' => 300, 'impressions' => 1000, 'clicks' => 20, 'leads' => 5, 'messages' => 0, 'currency' => 'TRY',
        ]);
        DB::table('meta_adset_targeting_snapshot')->insert($this->provenance() + [
            'external_resource_id' => $this->resource->id, 'account_id' => '777', 'adset_id' => 's1', 'campaign_id' => 'c1', 'adset_name' => 'Almanya Türkçe',
            'optimization_goal' => 'LEAD_GENERATION',
            'targeting' => json_encode(['geo_locations' => ['countries' => ['DE', 'NL'], 'cities' => [['key' => '1', 'name' => 'Berlin']]], 'age_min' => 30, 'locales' => [44], 'flexible_spec' => [['interests' => [['id' => '9', 'name' => 'Türkiye']]]]]),
        ]);
        DB::table('meta_ad_snapshot')->insert($this->provenance() + [
            'external_resource_id' => $this->resource->id, 'account_id' => '777', 'ad_id' => 'a1', 'adset_id' => 's1', 'effective_status' => 'ACTIVE',
        ]);

        $response = MoxdopServer::tool(MetaPerformance::class, ['brand_id' => $this->brand->id, 'days' => 90, 'by' => ['month', 'country', 'campaign', 'age_gender']]);

        $response->assertOk()
            ->assertSee('"spend":700')
            ->assertSee('"leads":8')
            ->assertSee('"messages":9')
            ->assertSee('"month":"2026-09"')
            ->assertSee('"country":"DE"')
            ->assertSee('"cost_per_lead":100')
            ->assertSee('"age":"35-44"')
            ->assertSee('"countries":["DE","NL"]')
            ->assertSee('"running":true')
            ->assertSee('interests: Türkiye')
            ->assertSee('"ACTIVE":1')
            ->assertDontSee('Başka marka')
            ->assertDontSee('Eski Kampanya');
    }

    public function test_an_unknown_brand_is_refused(): void
    {
        MoxdopServer::tool(MetaPerformance::class, ['brand_id' => 999999])->assertHasErrors();
        MoxdopServer::tool(MetaPerformance::class, ['brand_id' => $this->brand->id, 'by' => ['nationality']])->assertHasErrors();
    }

    private function geo(int $assetId, string $date, string $country, string $campaign, float $spend, float $leads, float $messages): void
    {
        DB::table('meta_geo_results_daily')->insert([
            'digital_asset_id' => $assetId, 'account_id' => '777', 'reporting_date' => $date, 'level' => 'country', 'ad_id' => 'a-'.$campaign,
            'ad_name' => 'Reklam '.$campaign, 'adset_id' => 's1', 'adset_name' => 'Set', 'campaign_id' => 'c-'.$campaign, 'campaign_name' => $campaign,
            'country' => $country, 'region' => '', 'spend' => $spend, 'impressions' => 10000, 'clicks' => 100, 'leads' => $leads, 'messages' => $messages, 'currency' => 'TRY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function provenance(): array
    {
        return ['digital_asset_id' => $this->asset->id, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];
    }
}
