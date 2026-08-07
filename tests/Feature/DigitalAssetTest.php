<?php

namespace Tests\Feature;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\DigitalAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitalAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_digital_asset_can_be_created_via_factory_and_persisted(): void
    {
        $brand = Brand::factory()->create();

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'name' => 'Acme Corporate Website',
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
            'module_id' => 'website',
        ]);

        $this->assertDatabaseHas('digital_assets', [
            'id' => $asset->id,
            'brand_id' => $brand->id,
            'name' => 'Acme Corporate Website',
            'type' => 'website',
            'status' => DigitalAssetStatus::Active->value,
            'module_id' => 'website',
        ]);

        $this->assertSame(DigitalAssetStatus::Active, $asset->status);
    }

    public function test_digital_asset_belongs_to_brand(): void
    {
        $brand = Brand::factory()->create([
            'name' => 'Acme Brand',
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'name' => 'Acme GBP',
            'type' => 'google_business_profile',
        ]);

        $this->assertTrue($asset->brand->is($brand));
        $this->assertSame('Acme Brand', $asset->brand->name);
        $this->assertTrue($brand->digitalAssets->contains($asset));
    }

    public function test_module_id_may_be_null(): void
    {
        $asset = DigitalAsset::factory()->create([
            'module_id' => null,
        ]);

        $this->assertDatabaseHas('digital_assets', [
            'id' => $asset->id,
            'module_id' => null,
        ]);

        $this->assertNull($asset->fresh()->module_id);
    }
}
