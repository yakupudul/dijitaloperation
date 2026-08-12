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
            ->assertSee('Cross-channel summary')
            ->call('runPublicResearch')
            ->assertSet('tab', 'research')
            ->assertSee('PUBLIC DISCOVERY')
            ->assertSee('atlasdental.example')
            ->call('runAiBrief')
            ->assertSet('tab', 'ai')
            ->assertSee('Brand analysis')
            ->assertSee('Create recommendation')
            ->call('createRecommendationFromPriority', 0)
            ->assertSet('tab', 'recommendations')
            ->assertSee('Replace underperforming Meta creative');
    }

    public function test_assets_index_filters_by_role_and_health(): void
    {
        Livewire::test(AssetsIndex::class)
            ->assertSee('Primary managed')
            ->set('filterRole', 'infrastructure')
            ->assertSee('DemoHost')
            ->assertDontSee('Atlas Dental — Meta')
            ->call('clearFilters')
            ->set('filterHealth', 'needs_attention')
            ->assertSee('atlasdental.example')
            ->assertSee('Atlas Dental — Meta');
    }
}
