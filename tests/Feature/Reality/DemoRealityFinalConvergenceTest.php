<?php

namespace Tests\Feature\Reality;

use App\Enums\DataPool\DataSourceState;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Operations\FindingsIndex;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\User;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\Ga4WorkspaceFixtures;
use App\Support\Roles;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 67 — production surfaces must not silently fall back to Demo business truth.
 */
class DemoRealityFinalConvergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
    }

    public function test_production_findings_index_is_empty_without_demo_fixtures(): void
    {
        Livewire::test(FindingsIndex::class)
            ->assertOk()
            ->assertSee('No Findings yet')
            ->assertDontSee('Meta CPL deteriorated');
    }

    public function test_production_findings_index_shows_persisted_findings_only(): void
    {
        $asset = DigitalAsset::factory()->create(['name' => 'Real GA4 Property']);
        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'title' => 'Canonical persisted Finding',
            'status' => Finding::STATUS_OPEN,
            'severity' => 'high',
        ]);

        Livewire::test(FindingsIndex::class)
            ->assertSee('Canonical persisted Finding')
            ->assertDontSee('Meta CPL deteriorated');
    }

    public function test_dashboard_does_not_inject_demo_recent_value_narrative(): void
    {
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('qualified leads recorded');
    }

    public function test_integrations_hub_does_not_fabricate_connected_last_check_for_non_google_meta(): void
    {
        $groups = app(OperatorIntegrationsHubQuery::class)->groups();
        $providers = collect($groups)->flatMap(fn (array $group) => $group['providers'] ?? []);

        foreach ($providers as $provider) {
            $id = (string) ($provider['id'] ?? '');
            if (in_array($id, ['google', 'meta'], true)) {
                continue;
            }

            $this->assertNotSame('connected', $provider['state'] ?? null, "Provider {$id} must not fake Connected");
            $this->assertSame('—', $provider['last_check'] ?? null, "Provider {$id} must not fake last_check");
            $this->assertSame('real', $provider['provenance'] ?? null);
        }
    }

    public function test_website_production_asset_uses_real_workspace_without_demo_fixtures(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'name' => 'Production Website Asset',
        ]);

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $asset->id])
            ->assertOk()
            ->assertSee('Production Website Asset')
            ->assertSee('Needs attention')
            ->assertSee(__('operator_runtime.website.sources'))
            ->assertSee(__('operator_runtime.website.public_discovery'))
            ->assertDontSee('Demo Mode · product vision fixtures')
            ->assertDontSee('Atlas Dental Website')
            ->assertDontSee('not yet available');
    }

    public function test_explicit_demo_catalog_ga4_asset_still_uses_demo_fixtures(): void
    {
        $workspace = app(Ga4SpecialistReadService::class)->workspace(DemoCatalog::GA4_ASSET_ID);
        $this->assertSame('demo_catalog', $workspace['migration_mode']);
        $baseline = Ga4WorkspaceFixtures::workspace('last_28');
        $this->assertNotEmpty($baseline['needs_attention']);
        $this->assertContains(DataSourceState::Demo->value, $workspace['data_provenance']);
    }

    public function test_database_seeder_does_not_create_demo_customers(): void
    {
        $before = Customer::query()->count();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, Customer::query()->count());
    }

    public function test_production_namespace_services_do_not_hardcode_connected_hub_cards(): void
    {
        $hub = file_get_contents(app_path('Services/Integrations/OperatorIntegrationsHubQuery.php'));
        $this->assertStringContainsString('truthfulProviderCard', $hub);
        $this->assertStringNotContainsString("'last_check' => 'Today'", $hub);
    }
}
