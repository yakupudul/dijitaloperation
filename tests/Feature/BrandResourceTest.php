<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $this->actingAs($this->admin);

        Filament::setCurrentPanel('app');

        $this->customer = Customer::factory()->create();
    }

    public function test_brand_cannot_be_created_without_a_customer(): void
    {
        $this->expectException(QueryException::class);

        Brand::query()->create([
            'name' => 'Orphan Brand',
            'sector' => 'retail',
        ]);
    }
}
