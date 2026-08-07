<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Filament\App\Resources\Customers\RelationManagers\BrandsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\CreateBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\EditBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\ViewBrand;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_brand_can_be_created_via_filament_nested_under_customer(): void
    {
        $responsible = User::factory()->create();

        Livewire::test(CreateBrand::class, [
            'parentRecord' => $this->customer,
        ])
            ->assertOk()
            ->fillForm([
                'name' => 'Acme Brand',
                'sector' => 'Retail',
                'primary_country' => 'TR',
                'target_markets' => ['TR', 'DE'],
                'languages' => ['tr', 'en'],
                'description' => 'A retail brand',
                'audience' => 'Urban shoppers',
                'offerings' => 'Apparel and accessories',
                'competitors' => 'Competitor A, Competitor B',
                'responsibleUsers' => [$responsible->id],
                'logo_url' => 'https://cdn.example.com/logo.png',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('brands', [
            'customer_id' => $this->customer->id,
            'name' => 'Acme Brand',
            'sector' => 'Retail',
            'primary_country' => 'TR',
            'description' => 'A retail brand',
            'audience' => 'Urban shoppers',
            'offerings' => 'Apparel and accessories',
            'competitors' => 'Competitor A, Competitor B',
            'logo_url' => 'https://cdn.example.com/logo.png',
        ]);

        $brand = Brand::query()->where('name', 'Acme Brand')->firstOrFail();

        $this->assertSame(['TR', 'DE'], $brand->target_markets);
        $this->assertSame(['tr', 'en'], $brand->languages);
        $this->assertTrue($brand->responsibleUsers->contains($responsible));
    }

    public function test_brand_cannot_be_created_without_a_customer(): void
    {
        $this->expectException(QueryException::class);

        Brand::query()->create([
            'name' => 'Orphan Brand',
            'sector' => 'Retail',
        ]);
    }

    public function test_brand_mvp_fields_are_visible_on_view_page(): void
    {
        $responsible = User::factory()->create(['name' => 'Brand Owner']);

        $brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Visible Brand',
            'sector' => 'SaaS',
            'primary_country' => 'US',
            'target_markets' => ['US', 'CA'],
            'languages' => ['en'],
            'description' => 'Brand description',
            'audience' => 'B2B buyers',
            'offerings' => 'Software tools',
            'competitors' => 'Rival Co',
            'logo_url' => 'https://cdn.example.com/visible.png',
        ]);
        $brand->responsibleUsers()->attach($responsible);

        Livewire::test(ViewBrand::class, [
            'record' => $brand->getRouteKey(),
            'parentRecord' => $this->customer,
        ])
            ->assertOk()
            ->assertSchemaStateSet([
                'customer.name' => $this->customer->name,
                'name' => 'Visible Brand',
                'sector' => 'SaaS',
                'primary_country' => 'US',
                'target_markets' => ['US', 'CA'],
                'languages' => ['en'],
                'description' => 'Brand description',
                'audience' => 'B2B buyers',
                'offerings' => 'Software tools',
                'competitors' => 'Rival Co',
                'logo_url' => 'https://cdn.example.com/visible.png',
                'responsibleUsers.name' => 'Brand Owner',
            ]);
    }

    public function test_brand_mvp_fields_are_editable_via_filament(): void
    {
        $brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Old Name',
        ]);

        $responsible = User::factory()->create();

        Livewire::test(EditBrand::class, [
            'record' => $brand->getRouteKey(),
            'parentRecord' => $this->customer,
        ])
            ->assertOk()
            ->fillForm([
                'name' => 'Updated Brand',
                'sector' => 'Finance',
                'primary_country' => 'DE',
                'target_markets' => ['DE', 'AT'],
                'languages' => ['de'],
                'description' => 'Updated description',
                'audience' => 'Updated audience',
                'offerings' => 'Updated offerings',
                'competitors' => 'Updated competitors',
                'responsibleUsers' => [$responsible->id],
                'logo_url' => 'https://cdn.example.com/updated.png',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $brand->refresh();

        $this->assertSame('Updated Brand', $brand->name);
        $this->assertSame('Finance', $brand->sector);
        $this->assertSame('DE', $brand->primary_country);
        $this->assertSame(['DE', 'AT'], $brand->target_markets);
        $this->assertSame(['de'], $brand->languages);
        $this->assertSame('Updated description', $brand->description);
        $this->assertSame('Updated audience', $brand->audience);
        $this->assertSame('Updated offerings', $brand->offerings);
        $this->assertSame('Updated competitors', $brand->competitors);
        $this->assertSame('https://cdn.example.com/updated.png', $brand->logo_url);
        $this->assertTrue($brand->responsibleUsers->contains($responsible));
        $this->assertSame($this->customer->id, $brand->customer_id);
    }

    public function test_brand_can_be_deleted_via_filament(): void
    {
        $brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
        ]);

        Livewire::test(EditBrand::class, [
            'record' => $brand->getRouteKey(),
            'parentRecord' => $this->customer,
        ])
            ->callAction(DeleteAction::class)
            ->assertNotified();

        $this->assertDatabaseMissing('brands', [
            'id' => $brand->id,
        ]);
    }

    public function test_brands_are_discoverable_from_customer_detail(): void
    {
        $brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Discoverable Brand',
        ]);

        Livewire::test(ViewCustomer::class, [
            'record' => $this->customer->getRouteKey(),
        ])
            ->assertOk()
            ->assertSeeLivewire(BrandsRelationManager::class);

        Livewire::test(BrandsRelationManager::class, [
            'ownerRecord' => $this->customer,
            'pageClass' => ViewCustomer::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$brand]);
    }

    public function test_brand_create_form_requires_name(): void
    {
        Livewire::test(CreateBrand::class, [
            'parentRecord' => $this->customer,
        ])
            ->fillForm([
                'name' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required'])
            ->assertNotNotified();
    }
}
