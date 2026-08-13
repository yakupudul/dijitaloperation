<?php

namespace Tests\Feature;

use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemoPortfolioUxTest extends TestCase
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

    public function test_brand_show_research_and_ai_actions_work(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->assertSee('Needs attention')
            ->assertSee('Digital estate')
            ->call('runPublicResearch')
            ->assertSet('tab', 'business')
            ->assertSet('businessSection', 'discovery')
            ->assertSet('discovery', 'overview')
            ->assertSee('Public Discovery')
            ->assertSee('Observed ≠ canonical')
            ->assertSee('No silent Brand Context mutation')
            ->call('setDiscovery', 'facts')
            ->assertSee('Observed Facts')
            ->assertSee('atlasdental.example')
            ->call('setDiscovery', 'candidates')
            ->assertSee('Dental Implant')
            ->call('runAiBrief')
            ->assertSet('tab', 'growth')
            ->assertSee(__('operator.brand.tabs.growth'))
            ->assertSee('Create recommendation')
            ->call('createRecommendationFromPriority', 0)
            ->assertSet('tab', 'operations')
            ->assertSet('ops', 'recommendations')
            ->assertSee('Replace underperforming Meta creative');

        $research = DemoState::all()['public_research'] ?? [];
        $this->assertTrue((bool) ($research['completed'] ?? false));
        $this->assertSame('atlasdental.example', $research['website'] ?? null);
    }

    public function test_assets_index_filters_by_role_and_health(): void
    {
        Livewire::test(AssetsIndex::class)
            ->assertSee('Managed Assets')
            ->assertSee('Digital Estate Directory')
            ->set('filterRole', 'infrastructure')
            ->assertSee('DemoHost')
            ->assertDontSee('Atlas Dental — Meta')
            ->call('clearFilters')
            ->set('filterHealth', 'needs_attention')
            ->assertSee('atlasdental.example')
            ->assertSee('Atlas Dental — Meta');
    }

    public function test_assets_estate_matrix_marks_missing_as_not_configured(): void
    {
        Livewire::test(AssetsIndex::class)
            ->call('setViewMode', 'matrix')
            ->assertSee('Estate Matrix')
            ->assertSee('Not configured')
            ->assertSee('Atlas Dental Ankara');
    }
}
