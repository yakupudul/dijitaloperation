<?php

namespace Tests\Feature;

use App\Enums\RecommendationSourceKind;
use App\Livewire\Demo\Operations\OpportunitiesIndex;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Task;
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
            ->assertDontSee('High paid implant demand but weak organic coverage')
            ->assertDontSee('Opportunity score')
            ->assertDontSee('Content coverage gap for priority offering');
    }

    public function test_opportunity_disposition_actions_are_db_backed(): void
    {
        $opportunity = Opportunity::factory()->create([
            'status' => Opportunity::STATUS_OPEN,
            'title' => 'Organic click recovery potential',
            'detection_state' => 'detected',
            'closed_at' => null,
        ]);
        $id = (string) $opportunity->id;

        Livewire::test(OpportunitiesIndex::class)
            ->set('view', 'all')
            ->assertSee('Organic click recovery potential')
            ->call('review', $id);

        $this->assertSame(Opportunity::STATUS_REVIEWING, $opportunity->fresh()->status);

        Livewire::test(OpportunitiesIndex::class)
            ->call('defer', $id);
        $this->assertSame(Opportunity::STATUS_DEFERRED, $opportunity->fresh()->status);

        $dismissed = Opportunity::factory()->create([
            'status' => Opportunity::STATUS_OPEN,
            'title' => 'Organic CTR improvement potential',
            'detection_state' => 'detected',
        ]);
        Livewire::test(OpportunitiesIndex::class)
            ->call('dismiss', (string) $dismissed->id);
        $this->assertSame(Opportunity::STATUS_DISMISSED, $dismissed->fresh()->status);

        $converted = Opportunity::factory()->create([
            'status' => Opportunity::STATUS_OPEN,
            'title' => 'Session recovery potential',
            'detection_state' => 'detected',
        ]);
        Livewire::test(OpportunitiesIndex::class)
            ->call('createRecommendation', (string) $converted->id);
        $this->assertSame(Opportunity::STATUS_CONVERTED, $converted->fresh()->status);

        $recommendation = Recommendation::query()->where('opportunity_id', $converted->id)->sole();
        $this->assertSame(RecommendationSourceKind::Opportunity->value, $recommendation->source_kind);
        $this->assertNull($recommendation->finding_id);
        $this->assertSame('Act on: Session recovery potential', $recommendation->title);
        $this->assertSame(0, Task::query()->count());
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

    public function test_brand_growth_shows_opportunity_section_without_demo_titles(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'growth')
            ->assertSee(__('operator.opportunities.growth_section'))
            ->assertDontSee('High paid implant demand but weak organic coverage');
    }

    public function test_brand_value_shows_business_outcomes_without_zero_revenue(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'value')
            ->call('setValueSection', 'outcomes')
            ->assertSee(__('operator.outcomes.title'))
            ->assertSee(__('operator.outcomes.platform_results'))
            ->assertSee(__('operator.outcomes.not_available'))
            ->assertDontSee('₺0');
    }

    public function test_opportunity_persistence_exists_while_deferred_entities_remain_deferred(): void
    {
        $this->assertTrue(Schema::hasTable('opportunities'));
        $this->assertTrue(Schema::hasTable('opportunity_evaluations'));

        foreach (['service_plans', 'business_outcomes'] as $table) {
            $this->assertFalse(Schema::hasTable($table), 'Unexpected table: '.$table);
        }

        $this->assertTrue(Schema::hasTable('brand_goals'));
        $this->assertTrue(Schema::hasTable('brand_offerings'));
        $this->assertTrue(Schema::hasTable('brand_offering_names'));

        $migrationPath = database_path('migrations');
        foreach (['*service_plans*', '*business_outcomes*'] as $pattern) {
            $matches = File::glob($migrationPath.'/'.$pattern);
            $this->assertEmpty($matches, 'Unexpected migration files for pattern: '.$pattern);
        }

        $this->assertNotEmpty(File::glob($migrationPath.'/*opportunities*'));
    }

    public function test_recommendation_source_distinguishes_opportunity_from_finding(): void
    {
        $opportunity = Opportunity::factory()->create([
            'status' => Opportunity::STATUS_OPEN,
            'title' => 'Organic click recovery potential',
        ]);
        $finding = Finding::factory()->create(['title' => 'Organic clicks declined']);

        $fromOpportunity = Recommendation::factory()->forOpportunity($opportunity)->create([
            'title' => 'Act on: Organic click recovery potential',
            'status' => Recommendation::STATUS_OPEN,
        ]);
        $fromFinding = Recommendation::factory()->forFinding($finding)->create([
            'title' => 'Fix: Organic clicks declined',
            'status' => Recommendation::STATUS_OPEN,
        ]);

        $this->get(route('demo.recommendations'))->assertOk();

        Livewire::test(RecommendationsIndex::class)
            ->call('expand', (string) $fromOpportunity->id)
            ->assertSee(__('operator.commercial.source_opportunity'))
            ->assertSee('Opportunity #'.$opportunity->id);

        Livewire::test(RecommendationsIndex::class)
            ->call('expand', (string) $fromFinding->id)
            ->assertSee(__('operator.commercial.source_finding'))
            ->assertSee('Finding #'.$finding->id);
    }

    public function test_digital_asset_scope_awareness_on_website_and_instagram(): void
    {
        $this->get(route('demo.website'))
            ->assertOk()
            ->assertSee(__('operator.commercial.managed_under'))
            ->assertSee('Website Maintenance');

        $this->get(route('demo.instagram'))
            ->assertOk()
            ->assertSee(__('operator.commercial.outside_scope'));
    }
}
