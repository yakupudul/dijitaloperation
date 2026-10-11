<?php

namespace Tests\Feature\Brand;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Customer;
use App\Models\User;
use App\Services\Brand\BrandFacts;
use App\Services\Brand\BrandPack;
use App\Services\Catalog\ServiceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every AI operation is told the same about a brand (yakup, 2026-10-10): ★ services first, only active places, the
 * brand's languages else its site's.
 */
final class BrandPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_places_and_languages_are_the_brands_active_ones(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'languages' => ['tr', 'en']]);
        $catalog = app(ServiceCatalogService::class);
        $admin = User::factory()->create();
        foreach (['Diş Beyazlatma' => 'secondary', 'İmplant Tedavisi' => 'main'] as $name => $priority) {
            BrandOffering::query()->create(['brand_id' => $brand->id, 'service_catalog_item_id' => $catalog->resolveOrCreate($name, 'saglik', actor: $admin)['service']->id,
                'status' => 'active', 'priority' => $priority]);
        }
        BrandServiceArea::query()->create(['brand_id' => $brand->id, 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya', 'normalized_key' => 'tr|ankara|cankaya', 'status' => 'active']);
        BrandServiceArea::query()->create(['brand_id' => $brand->id, 'country_code' => 'TR', 'city_name' => 'İzmir', 'district_name' => 'Seferihisar', 'normalized_key' => 'tr|izmir|seferihisar', 'status' => 'archived']);

        $this->assertSame([['name' => 'İmplant Tedavisi', 'priority' => 'main'], ['name' => 'Diş Beyazlatma', 'priority' => 'secondary']], BrandPack::services($brand));
        $this->assertSame(['Çankaya'], BrandPack::areaNames($brand), 'an archived place is not sent to the AI');
        $this->assertCount(1, BrandPack::areas($brand));
        $this->assertSame(['tr', 'en'], BrandPack::languages($brand));
        $this->assertSame([], BrandPack::services(null));
    }

    public function test_a_service_starred_through_either_field_comes_first(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $catalog = app(ServiceCatalogService::class);
        $admin = User::factory()->create();
        foreach (['Dolgu' => [], 'İmplant Tedavisi' => ['is_priority' => true]] as $name => $extra) {
            $id = BrandOffering::query()->insertGetId($extra + ['brand_id' => $brand->id, 'status' => 'active', 'priority' => 'secondary', 'is_priority' => false,
                'service_catalog_item_id' => $catalog->resolveOrCreate($name, 'saglik', actor: $admin)['service']->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->assertGreaterThan(0, $id);
        }

        $this->assertSame([['name' => 'İmplant Tedavisi', 'priority' => 'main'], ['name' => 'Dolgu', 'priority' => 'secondary']], BrandPack::services($brand));
        $this->assertSame(['★ İmplant Tedavisi', 'Dolgu'], app(BrandFacts::class)->build($brand)['fields']['services']['items']);
    }
}
