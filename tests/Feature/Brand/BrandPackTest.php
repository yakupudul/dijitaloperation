<?php

namespace Tests\Feature\Brand;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Customer;
use App\Models\User;
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
}
