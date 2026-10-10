<?php

namespace Tests\Feature\Brand;

use App\Jobs\Brand\RefreshBrandFilesJob;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use App\Services\Brand\BrandFacts;
use App\Services\Brand\BrandScope;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Ownership\OwnershipIntegrity;
use App\Services\Site\SiteScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One answer to "which accounts are this brand's" (yakup, 2026-10-10): a Search Console property bound to a separate
 * gsc asset measures the brand's website too, and is not reported as a wrong binding.
 */
final class BrandScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_console_on_a_separate_asset_belongs_to_the_brand_and_its_site(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlasdis.com', 'primary_url' => 'https://atlasdis.com/']);
        $gsc = $this->bind($brand, 'gsc', 'search_console', 'sc-domain:atlasdis.com');

        $this->assertSame([$gsc], BrandScope::resources((int) $brand->id, 'search_console'));
        $this->assertSame([$gsc], SiteScope::resourceIds($brand, 'search_console'));
        $this->assertSame([$gsc], BrandScope::siteResources($site, 'search_console'), 'the only website of the brand');
        $this->assertSame([], array_values(array_filter(app(OwnershipIntegrity::class)->problems(), fn (array $p): bool => $p['code'] === 'binding_type_mismatch')));
    }

    public function test_with_two_websites_a_property_goes_to_the_site_it_names(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $tr = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlasdis.com', 'primary_url' => 'https://atlasdis.com/']);
        $en = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlasdental.co.uk', 'primary_url' => 'https://www.atlasdental.co.uk/']);
        $trProperty = $this->bind($brand, 'gsc', 'search_console', 'sc-domain:atlasdis.com');
        $enProperty = $this->bind($brand, 'gsc', 'search_console', 'https://www.atlasdental.co.uk/');
        $own = $this->bind($brand, 'website', 'search_console', 'https://atlasdis.com/', $tr);

        $this->assertSame([$own], BrandScope::siteResources($tr, 'search_console'), 'a binding on the site itself wins');
        $this->assertSame([$enProperty], BrandScope::siteResources($en, 'search_console'));
        $this->assertContains($trProperty, BrandScope::resources((int) $brand->id, 'search_console'));
    }

    public function test_the_brand_context_copies_follow_the_brands_own_services_and_places(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        BrandIntelligenceContext::withLegacyIdentityProjection(fn () => BrandIntelligenceContext::query()->create(['brand_id' => $brand->id,
            'products_services' => [['name' => 'Eski yazı']], 'target_markets' => [['name' => 'Eski yer']], 'priority_offerings' => [], 'business_goals' => [], 'conversion_goals' => []]));
        $catalog = app(ServiceCatalogService::class);
        $admin = User::factory()->create();
        foreach (['İmplant Tedavisi' => 'main', 'Diş Beyazlatma' => 'secondary'] as $name => $priority) {
            BrandOffering::query()->create(['brand_id' => $brand->id, 'service_catalog_item_id' => $catalog->resolveOrCreate($name, 'saglik', actor: $admin)['service']->id,
                'status' => 'active', 'priority' => $priority]);
        }
        BrandServiceArea::query()->create(['brand_id' => $brand->id, 'country_code' => 'TR', 'city_name' => 'İzmir', 'district_name' => 'Bornova', 'normalized_key' => 'tr|izmir|bornova', 'status' => 'active', 'physical_branch' => true]);

        (new RefreshBrandFilesJob((int) $brand->id))->handle(app(BrandDossier::class), app(BrandFacts::class));

        $context = BrandIntelligenceContext::query()->where('brand_id', $brand->id)->firstOrFail();
        $this->assertSame(['İmplant Tedavisi'], $context->priority_offerings, 'the ★ service is the priority offering');
        $this->assertSame(['İmplant Tedavisi', 'Diş Beyazlatma'], array_column($context->products_services, 'name'));
        $this->assertSame([['name' => 'Bornova, İzmir, TR', 'note' => 'şube']], $context->target_markets);
        $this->assertSame(['TR'], $brand->fresh()->target_markets);
    }

    private function bind(Brand $brand, string $assetType, string $resourceType, string $externalId, ?DigitalAsset $asset = null): int
    {
        $asset ??= DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $assetType]);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => $resourceType, 'external_id' => $externalId, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $resourceType, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return (int) $resource->id;
    }
}
