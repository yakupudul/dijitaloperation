<?php

namespace Tests\Feature\Brand;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Brand\BrandScope;
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

    private function bind(Brand $brand, string $assetType, string $resourceType, string $externalId, ?DigitalAsset $asset = null): int
    {
        $asset ??= DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $assetType]);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => $resourceType, 'external_id' => $externalId, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $resourceType, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return (int) $resource->id;
    }
}
