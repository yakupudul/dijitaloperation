<?php

namespace Tests\Feature;

use App\Filament\App\Clusters\Settings\Pages\AiControlPlaneSettings;
use App\Filament\App\Resources\Modules\ModuleResource;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Operations\ActivityIndex;
use App\Livewire\Demo\Operations\FindingsIndex;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\SettingsPage;
use App\Models\User;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetTypes;
use App\Support\DigitalAssetVisualCatalog;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductVisionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');
        DemoState::reset();
    }

    public function test_canonical_asset_types_include_ga4_and_gsc_without_domain_hosting(): void
    {
        $options = DigitalAssetTypes::options();
        $this->assertArrayHasKey('ga4', $options);
        $this->assertArrayHasKey('gsc', $options);
        $this->assertSame('Google Analytics', $options['ga4']);
        $this->assertArrayNotHasKey('domain', $options);
        $this->assertArrayNotHasKey('hosting', $options);
        $this->assertSame('ga4', DigitalAssetVisualCatalog::normalizeType('google_analytics'));
        $this->assertSame('gsc', DigitalAssetVisualCatalog::normalizeType('search_console'));
    }

    public function test_modules_are_hidden_from_operator_navigation(): void
    {
        $this->assertFalse(ModuleResource::shouldRegisterNavigation());
    }

    public function test_ai_control_plane_enumerates_all_registered_routes(): void
    {
        $registry = app(AiRouteRegistry::class);
        foreach ([
            AiRouteKeys::WEBSITE_AI_GUIDANCE,
            AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT,
            AiRouteKeys::GOOGLE_ADS_AI_GUIDANCE,
            AiRouteKeys::META_ADS_AI_GUIDANCE,
            AiRouteKeys::GBP_AI_GUIDANCE,
            AiRouteKeys::GA4_AI_GUIDANCE,
            AiRouteKeys::GSC_AI_GUIDANCE,
        ] as $key) {
            $this->assertTrue($registry->has($key), 'Missing AI route: '.$key);
        }

        Livewire::test(AiControlPlaneSettings::class)
            ->assertOk()
            ->assertSee('Registered AI routes')
            ->assertSee('Website AI Guidance')
            ->assertSee('Website Discovery Context')
            ->assertSee('Google Ads')
            ->assertSee('Meta Ads')
            ->assertSee('GBP Local Presence Guidance')
            ->assertSee('GA4 Measurement Guidance')
            ->assertSee('Search Console Organic Search Guidance')
            ->call('selectRoute', AiRouteKeys::META_ADS_AI_GUIDANCE)
            ->assertSet('selectedRoute', AiRouteKeys::META_ADS_AI_GUIDANCE)
            ->assertSee('meta_ads.ai_guidance');
    }

    public function test_specialist_agent_profiles_are_registered(): void
    {
        $agents = app(AgentProfileRegistry::class);
        $this->assertTrue($agents->has(AgentProfileKeys::GBP_LOCAL_PRESENCE_ANALYST));
        $this->assertTrue($agents->has(AgentProfileKeys::GA4_MEASUREMENT_ANALYST));
        $this->assertTrue($agents->has(AgentProfileKeys::GSC_ORGANIC_SEARCH_ANALYST));
        $this->assertSame('designed', $agents->get(AgentProfileKeys::GBP_LOCAL_PRESENCE_ANALYST)->status);
        $this->assertSame(AiRouteKeys::GA4_AI_GUIDANCE, $agents->get(AgentProfileKeys::GA4_MEASUREMENT_ANALYST)->aiRouteKey);
    }

    public function test_findings_support_acknowledge_and_resolve_actions(): void
    {
        Livewire::test(FindingsIndex::class)
            ->assertOk()
            ->call('acknowledge', 'f-lead-measurement')
            ->assertSee('Finding acknowledged')
            ->call('resolve', 'f-lead-measurement')
            ->assertSee('Finding resolved');

        $statuses = DemoState::all()['finding_statuses'] ?? [];
        $this->assertSame('resolved', $statuses['f-lead-measurement'] ?? null);
    }

    public function test_recommendations_support_defer_decision(): void
    {
        Livewire::test(RecommendationsIndex::class)
            ->assertOk()
            ->call('defer', 'r-review-conversion-mapping')
            ->assertSee('deferred');

        $rec = collect(DemoState::all()['recommendations'])->firstWhere('id', 'r-review-conversion-mapping');
        $this->assertSame('deferred', $rec['status'] ?? null);
    }

    public function test_activity_period_filter_excludes_older_seed_events(): void
    {
        Livewire::test(ActivityIndex::class)
            ->assertOk()
            ->set('period', 'last_7')
            ->assertDontSee('Hosting probe failed')
            ->set('period', 'last_90')
            ->assertSee('Hosting probe failed');
    }

    public function test_brand_business_context_is_editable_as_canonical_source(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->assertOk()
            ->call('startEditingContext')
            ->set('context_business_summary', 'Updated Atlas Dental canonical summary')
            ->call('saveBusinessContext')
            ->assertSee('Updated Atlas Dental canonical summary');

        $saved = DemoState::brandBusinessContext(DemoCatalog::BRAND_ID);
        $this->assertSame('Updated Atlas Dental canonical summary', $saved['business_summary'] ?? null);
    }

    public function test_settings_persist_general_and_notification_overrides(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertOk()
            ->set('section', 'general')
            ->set('agency_name', 'Moximu Agency Demo')
            ->call('saveGeneral')
            ->assertSet('agency_name', 'Moximu Agency Demo')
            ->set('section', 'ai')
            ->assertSee('Registered AI routes')
            ->assertSee('gbp.ai_guidance')
            ->assertSee('ga4.ai_guidance')
            ->assertSee('gsc.ai_guidance')
            ->assertSee('GBP Local Presence Analyst');

        $this->assertSame('Moximu Agency Demo', DemoState::settingsOverrides()['general']['agency_name'] ?? null);
    }

    public function test_app_shell_surfaces_remain_reachable(): void
    {
        Livewire::test(Dashboard::class)->assertOk()->assertSee('Needs your attention')->assertSee('My Work');

        $this->get('/app/assets')->assertOk();
        $this->get('/app/assets/analytics')->assertOk();
        $this->get('/app/assets/search-console')->assertOk();
        $this->get('/app/assets/gbp')->assertOk();
        $this->get('/app/setup')->assertOk();
        $this->get('/app/integrations/connectors/ga4')->assertOk();
        $this->get('/app/integrations/connectors/gsc')->assertOk();
        $this->get('/app/settings?section=ai')->assertOk();
    }
}
