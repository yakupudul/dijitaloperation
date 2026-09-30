<?php

namespace Tests\Feature;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\User;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class CommercialGrowthIntelligenceTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
        $this->seedCanonicalPortfolio();
    }

    public function test_customer_relationship_shows_service_scope(): void
    {
        $this->get(route('operator.customer', ['customerId' => $this->portfolioCustomer->id, 'tab' => 'relationship']))
            ->assertOk()
            ->assertSee(__('operator.service_scope.title'));
    }

    public function test_brand_business_shows_goals_and_agency_scope(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->call('setTab', 'business')
            ->assertSee('İş bağlamı')
            ->assertSee('Ajansın verdiği hizmetler');
    }

    public function test_retired_brand_work_tabs_open_the_overview(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->call('setTab', 'growth')
            ->assertSet('tab', 'overview')
            ->call('setTab', 'work')
            ->assertSet('tab', 'overview')
            ->assertDontSee('High paid implant demand but weak organic coverage');
    }

    public function test_opportunity_persistence_exists_while_deferred_entities_remain_deferred(): void
    {
        $this->assertTrue(Schema::hasTable('opportunities'));
        $this->assertTrue(Schema::hasTable('opportunity_evaluations'));

        foreach (['service_plans', 'leads', 'patients', 'deals', 'pipelines', 'appointments', 'invoices', 'payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), 'Unexpected CRM/deferred table: '.$table);
        }

        $this->assertTrue(Schema::hasTable('brand_goals'));
        $this->assertTrue(Schema::hasTable('brand_offerings'));
        $this->assertTrue(Schema::hasTable('brand_offering_names'));

        $migrationPath = database_path('migrations');
        foreach (['*service_plans*', '*create_leads*', '*create_patients*', '*create_deals*'] as $pattern) {
            $matches = File::glob($migrationPath.'/'.$pattern);
            $this->assertEmpty($matches, 'Unexpected CRM migration files for pattern: '.$pattern);
        }

        $this->assertNotEmpty(File::glob($migrationPath.'/*opportunities*'));
        $this->assertNotEmpty(File::glob($migrationPath.'/*business_outcome*'));
    }

    public function test_digital_asset_scope_awareness_on_website_and_instagram(): void
    {
        $website = $this->createPortfolioAsset('website', 'Northwind Website');
        $instagram = $this->createPortfolioAsset('instagram', 'Northwind Instagram');

        $this->get(route('operator.website'))->assertNotFound();
        $this->get(route('operator.instagram'))->assertRedirect(route('operator.assets'));

        $this->get(route('operator.website', ['assetId' => $website->id]))
            ->assertOk()
            ->assertSee('Northwind Website')
            ->assertDontSee('Atlas Dental Website');

        $this->get(route('operator.instagram', ['assetId' => $instagram->id]))
            ->assertRedirect(route('operator.assets'));
    }
}
