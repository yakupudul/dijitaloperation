<?php

namespace Tests\Feature\Assets;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shared asset frame (breadcrumb, sibling switcher, status strip) on every asset page, and GA4 / Search
 * Console treated as Website sources.
 */
final class AssetContextTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $website;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Örnek Holding'])->id, 'name' => 'Örnek Klinik']);
        $this->website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'Örnek Site', 'domain' => 'ornek.test']);
    }

    public function test_every_asset_page_shows_the_same_frame(): void
    {
        $assets = [$this->website];
        foreach (['google_ads', 'meta_ads', 'google_business_profile', 'ga4', 'gsc', 'instagram'] as $type) {
            $assets[] = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type, 'name' => 'Örnek '.$type]);
        }

        foreach ($assets as $asset) {
            $this->get(OperatorPortfolioPresenter::specialistUrl($asset))
                ->assertOk()
                ->assertSee('data-asset-context', false)
                ->assertSee('Örnek Holding')
                ->assertSee(route('operator.asset.edit', ['assetId' => $asset->id]), false)
                ->assertSee('Örnek meta_ads');
        }

        $this->get(route('operator.asset.sources', ['assetId' => $this->website->id]))->assertOk()->assertSee('data-asset-context', false);
        $this->get(route('operator.asset.edit', ['assetId' => $this->website->id]))->assertOk()->assertSee('data-asset-context', false);
    }

    public function test_ga4_and_search_console_count_as_website_sources(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $property = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'ga4', 'external_id' => 'properties/1', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->website->id, 'external_resource_id' => $property->id, 'capability' => 'ga4', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $legacyGa4 = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'ga4', 'name' => 'Eski GA4']);

        $matrix = OperatorPortfolioPresenter::estateMatrix(Brand::query()->whereKey($this->brand->id)->get());
        $cells = $matrix['rows'][0]['cells'];
        $this->assertSame('present', $cells['ga4']['state']);
        $this->assertSame(route('operator.website', ['assetId' => $this->website->id, 'tab' => 'ga4_analysis']), $cells['ga4']['url']);
        $this->assertSame('not_configured', $cells['gsc']['state']);

        $this->get(route('operator.analytics', ['assetId' => $legacyGa4->id]))
            ->assertOk()
            ->assertSee(__('operator_asset.website_home_link'))
            ->assertSee(route('operator.website', ['assetId' => $this->website->id, 'tab' => 'ga4_analysis']), false);
    }
}
