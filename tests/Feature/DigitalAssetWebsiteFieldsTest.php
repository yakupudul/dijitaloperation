<?php

namespace Tests\Feature;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\DigitalAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitalAssetWebsiteFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_digital_asset_website_fields_persist_with_array_casts(): void
    {
        $brand = Brand::factory()->create();

        $asset = DigitalAsset::query()->create([
            'brand_id' => $brand->id,
            'name' => 'Acme Corporate Website',
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
            'module_id' => 'website',
            'domain' => 'acme.example',
            'primary_url' => 'https://acme.example',
            'cms' => 'wordpress',
            'languages' => ['en', 'tr'],
            'target_countries' => ['TR', 'DE'],
            'site_type' => 'corporate',
            'hosting_context' => 'Managed WordPress on shared hosting',
        ]);

        $this->assertDatabaseHas('digital_assets', [
            'id' => $asset->id,
            'brand_id' => $brand->id,
            'domain' => 'acme.example',
            'primary_url' => 'https://acme.example',
            'cms' => 'wordpress',
            'site_type' => 'corporate',
            'hosting_context' => 'Managed WordPress on shared hosting',
        ]);

        $fresh = $asset->fresh();

        $this->assertSame(['en', 'tr'], $fresh->languages);
        $this->assertSame(['TR', 'DE'], $fresh->target_countries);
        $this->assertIsArray($fresh->languages);
        $this->assertIsArray($fresh->target_countries);
        $this->assertSame('acme.example', $fresh->domain);
        $this->assertSame('https://acme.example', $fresh->primary_url);
        $this->assertSame('wordpress', $fresh->cms);
        $this->assertSame('corporate', $fresh->site_type);
        $this->assertSame('Managed WordPress on shared hosting', $fresh->hosting_context);
    }

    public function test_digital_asset_website_fields_may_be_null(): void
    {
        $asset = DigitalAsset::factory()->create([
            'domain' => null,
            'primary_url' => null,
            'cms' => null,
            'languages' => null,
            'target_countries' => null,
            'site_type' => null,
            'hosting_context' => null,
        ]);

        $fresh = $asset->fresh();

        $this->assertNull($fresh->domain);
        $this->assertNull($fresh->primary_url);
        $this->assertNull($fresh->cms);
        $this->assertNull($fresh->languages);
        $this->assertNull($fresh->target_countries);
        $this->assertNull($fresh->site_type);
        $this->assertNull($fresh->hosting_context);
    }
}
