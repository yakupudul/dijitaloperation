<?php

namespace Tests\Support;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;

trait CreatesCanonicalPortfolio
{
    protected Customer $portfolioCustomer;

    protected Brand $portfolioBrand;

    protected function seedCanonicalPortfolio(?string $customerName = 'Northwind Clinics', ?string $brandName = 'Northwind Brand'): Brand
    {
        $this->portfolioCustomer = Customer::factory()->create(['name' => $customerName]);
        $this->portfolioBrand = Brand::factory()->create([
            'customer_id' => $this->portfolioCustomer->id,
            'name' => $brandName,
        ]);

        return $this->portfolioBrand;
    }

    protected function createPortfolioAsset(string $type, string $name, array $overrides = []): DigitalAsset
    {
        if (! isset($this->portfolioBrand)) {
            $this->seedCanonicalPortfolio();
        }

        return DigitalAsset::factory()->create(array_merge([
            'brand_id' => $this->portfolioBrand->id,
            'type' => $type,
            'name' => $name,
        ], $overrides));
    }
}
