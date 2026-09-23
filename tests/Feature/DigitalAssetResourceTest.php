<?php

namespace Tests\Feature;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitalAssetResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $this->actingAs($this->admin);

        Filament::setCurrentPanel('app');

        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_digital_asset_cannot_be_created_without_a_brand(): void
    {
        $this->expectException(QueryException::class);

        DigitalAsset::query()->create([
            'name' => 'Orphan Asset',
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
    }
}
