<?php

namespace Tests\Unit\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Collection\CollectionBindingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectionBindingScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function same_asset_is_always_eligible(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $asset->id,
            'brand_id' => $brand->id,
            'customer_id' => $customer->id,
            'status' => CollectionRunStatus::Running,
            'request_context' => ['context' => ['allow_multi_asset_bindings' => false]],
        ]);

        $this->assertTrue(CollectionBindingScope::collectionRunMayTargetAsset($run, $asset));
    }

    #[Test]
    public function same_brand_same_customer_sibling_requires_multi_asset_flag(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $ads = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $withoutFlag = CollectionRun::factory()->create([
            'digital_asset_id' => $website->id,
            'brand_id' => $brand->id,
            'customer_id' => $customer->id,
            'request_context' => ['context' => ['allow_multi_asset_bindings' => false]],
        ]);
        $withFlag = CollectionRun::factory()->create([
            'digital_asset_id' => $website->id,
            'brand_id' => $brand->id,
            'customer_id' => $customer->id,
            'request_context' => ['context' => ['allow_multi_asset_bindings' => true]],
        ]);

        $this->assertFalse(CollectionBindingScope::collectionRunMayTargetAsset($withoutFlag, $ads->load('brand')));
        $this->assertTrue(CollectionBindingScope::collectionRunMayTargetAsset($withFlag, $ads->load('brand')));
    }

    #[Test]
    public function multi_asset_flag_does_not_allow_other_brand_or_other_customer(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherBrandSameCustomer = Brand::factory()->create(['customer_id' => $customer->id]);
        $otherBrandAsset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrandSameCustomer->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherCustomer = Customer::factory()->create();
        $otherCustomerBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);
        $otherCustomerAsset = DigitalAsset::factory()->create([
            'brand_id' => $otherCustomerBrand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $website->id,
            'brand_id' => $brand->id,
            'customer_id' => $customer->id,
            'request_context' => ['context' => ['allow_multi_asset_bindings' => true]],
        ]);

        $this->assertFalse(CollectionBindingScope::collectionRunMayTargetAsset($run, $otherBrandAsset->load('brand')));
        $this->assertFalse(CollectionBindingScope::collectionRunMayTargetAsset($run, $otherCustomerAsset->load('brand')));

        $this->assertTrue(CollectionBindingScope::anchorMayTargetAsset(
            (int) $website->id,
            (int) $brand->id,
            (int) $customer->id,
            $otherBrandAsset->load('brand'),
            true,
            false,
        ));
        $this->assertFalse(CollectionBindingScope::anchorMayTargetAsset(
            (int) $website->id,
            (int) $brand->id,
            (int) $customer->id,
            $otherCustomerAsset->load('brand'),
            true,
            false,
        ));
    }

    #[Test]
    public function mismatched_collection_run_customer_id_is_rejected_even_when_brand_ids_match(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $ads = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $foreignCustomer = Customer::factory()->create();

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $website->id,
            'brand_id' => $brand->id,
            'customer_id' => $foreignCustomer->id,
            'request_context' => ['context' => ['allow_multi_asset_bindings' => true]],
        ]);

        $this->assertFalse(CollectionBindingScope::collectionRunMayTargetAsset($run, $ads->load('brand')));
    }
}
