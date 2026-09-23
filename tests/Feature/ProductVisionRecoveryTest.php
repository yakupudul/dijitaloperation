<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Modules\ModuleResource;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Operations\ActivityIndex;
use App\Livewire\Demo\Operations\FindingsIndex;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\SettingsPage;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\User;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
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
        $this->assertTrue($registry->has(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT), 'Missing AI route: '.AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT);

        // Faz 1 (ADR-065): module AI guidance routes are no longer registered.
        foreach (['website.ai_guidance', 'google_ads.ai_guidance', 'meta_ads.ai_guidance', 'gbp.ai_guidance', 'ga4.ai_guidance', 'gsc.ai_guidance'] as $key) {
            $this->assertFalse($registry->has($key), 'Removed AI route still registered: '.$key);
        }
    }

    public function test_findings_support_acknowledge_and_resolve_actions(): void
    {
        $asset = DigitalAsset::factory()->create();
        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => Finding::STATUS_OPEN,
            'severity' => 'high',
            'title' => 'Lead measurement gap',
        ]);

        Livewire::test(FindingsIndex::class)
            ->assertOk()
            ->assertSee('Lead measurement gap')
            ->call('acknowledge', (string) $finding->id)
            ->assertSee('Finding acknowledged')
            ->call('resolve', (string) $finding->id)
            ->assertSee('Finding resolved');

        $this->assertSame(Finding::STATUS_RESOLVED, $finding->fresh()->status);
    }

    public function test_recommendations_support_defer_decision(): void
    {
        $recommendation = Recommendation::factory()->create([
            'title' => 'Review conversion mapping for primary lead signal',
            'status' => Recommendation::STATUS_OPEN,
        ]);

        Livewire::test(RecommendationsIndex::class)
            ->assertOk()
            ->call('defer', (string) $recommendation->id)
            ->assertSee('deferred');

        // Defer is a review posture: the canonical statuses stay open/accepted/dismissed/converted.
        $this->assertSame(Recommendation::STATUS_OPEN, $recommendation->fresh()->status);
    }

    public function test_activity_period_filter_reads_production_store_without_demo_seed(): void
    {
        Livewire::test(ActivityIndex::class)
            ->assertOk()
            ->set('period', 'last_7')
            ->assertSee('No activity matches this view')
            ->assertDontSee('Hosting probe failed')
            ->set('period', 'last_90')
            ->assertDontSee('Hosting probe failed')
            ->assertSee('No activity matches this view');
    }

    public function test_brand_business_context_is_editable_as_canonical_source(): void
    {
        $brand = Brand::factory()->create(['name' => 'Northwind Brand']);

        Livewire::test(BrandShow::class, ['brand' => (string) $brand->id])
            ->assertOk()
            ->call('startEditingContext')
            ->set('context_business_summary', 'Updated Northwind canonical summary')
            ->call('saveBusinessContext')
            ->assertSee('Updated Northwind canonical summary');

        $this->assertSame('Updated Northwind canonical summary', $brand->fresh()->intelligenceContext?->business_summary);
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
            ->assertSee(__('operator.settings.ai.routes_title'));

        $this->assertSame('Moximu Agency Demo', AgencySetting::query()->first()?->agency_name);
        $this->assertSame([], DemoState::settingsOverrides());
    }

    public function test_app_shell_surfaces_remain_reachable(): void
    {
        Livewire::test(Dashboard::class)->assertOk()->assertSee(__('operator.dashboard_exec.needs_attention'))->assertSee('My Work');

        $this->get('/assets')->assertOk();
        $this->get('/assets/analytics')->assertNotFound();
        $this->get('/assets/search-console')->assertNotFound();
        $this->get('/assets/gbp')->assertNotFound();
        $this->get('/setup')->assertNotFound(); // setup happens through "Otomatik kur" on the brand
        $this->get('/integrations/connectors/ga4')->assertOk();
        $this->get('/integrations/connectors/gsc')->assertOk();
        $this->get('/settings?section=ai')->assertOk();
    }
}
