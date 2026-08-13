<?php

namespace Tests\Feature;

use App\Livewire\Demo\Operations\OpportunitiesIndex;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class CommercialGrowthIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
    }

    public function test_opportunities_nav_and_route_load_under_app(): void
    {
        $url = route('demo.opportunities');

        $this->assertStringNotContainsString('/system', $url);

        $this->get($url)
            ->assertOk()
            ->assertSee(__('operator.nav.opportunities'));

        Livewire::test(OpportunitiesIndex::class)
            ->assertSee('High paid implant demand but weak organic coverage')
            ->assertDontSee('Opportunity score');
    }

    public function test_opportunity_demo_state_actions(): void
    {
        Livewire::test(OpportunitiesIndex::class)
            ->call('review', 'opp-implant-organic-gap')
            ->assertSet('view', 'open');

        $statuses = DemoState::all()['opportunity_statuses'] ?? [];
        $this->assertSame('reviewing', $statuses['opp-implant-organic-gap'] ?? null);

        Livewire::test(OpportunitiesIndex::class)
            ->call('defer', 'opp-content-coverage');

        $statuses = DemoState::all()['opportunity_statuses'] ?? [];
        $this->assertSame('deferred', $statuses['opp-content-coverage'] ?? null);

        Livewire::test(OpportunitiesIndex::class)
            ->call('dismiss', 'opp-meta-creative-angle');

        $statuses = DemoState::all()['opportunity_statuses'] ?? [];
        $this->assertSame('dismissed', $statuses['opp-meta-creative-angle'] ?? null);

        Livewire::test(OpportunitiesIndex::class)
            ->call('createRecommendation', 'opp-gbp-local-gap');

        $statuses = DemoState::all()['opportunity_statuses'] ?? [];
        $this->assertSame('converted', $statuses['opp-gbp-local-gap'] ?? null);

        $rec = collect(DemoState::all()['recommendations'] ?? [])->firstWhere('id', 'r-from-opp-gbp-local-gap');
        $this->assertNotNull($rec);
        $this->assertSame('opp-gbp-local-gap', $rec['source_opportunity_id'] ?? null);
    }

    public function test_customer_relationship_shows_service_scope(): void
    {
        $this->get(route('demo.customer', ['customerId' => DemoCatalog::CUSTOMER_ID, 'tab' => 'relationship']))
            ->assertOk()
            ->assertSee(__('operator.service_scope.title'))
            ->assertSee('Google Ads Management')
            ->assertSee('campaign monitoring');
    }

    public function test_brand_business_shows_goals_and_agency_scope(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'business')
            ->call('setBusinessSection', 'context')
            ->assertSee(__('operator.goals.title'))
            ->assertSee(__('operator.commercial.agency_scope'))
            ->assertSee('Increase qualified implant consultations');
    }

    public function test_brand_growth_shows_opportunity_titles(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'growth')
            ->assertSee('High paid implant demand but weak organic coverage')
            ->assertSee(__('operator.opportunities.growth_section'));
    }

    public function test_brand_value_shows_business_outcomes_without_zero_revenue(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'value')
            ->assertSee(__('operator.outcomes.title'))
            ->assertSee(__('operator.outcomes.platform_results'))
            ->assertSee(__('operator.outcomes.not_available'))
            ->assertDontSee('₺0');
    }

    public function test_no_persistence_tables_or_migrations_for_commercial_entities(): void
    {
        foreach (['opportunities', 'service_plans', 'business_outcomes', 'goals'] as $table) {
            $this->assertFalse(Schema::hasTable($table), 'Unexpected table: '.$table);
        }

        $migrationPath = database_path('migrations');
        $patterns = ['*opportunities*', '*service_plans*', '*business_outcomes*', '*goals*'];

        foreach ($patterns as $pattern) {
            $matches = File::glob($migrationPath.'/'.$pattern);
            $this->assertEmpty($matches, 'Unexpected migration files for pattern: '.$pattern);
        }
    }
}
