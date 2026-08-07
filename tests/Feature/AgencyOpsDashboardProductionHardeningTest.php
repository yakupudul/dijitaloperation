<?php

namespace Tests\Feature;

use App\Filament\App\Widgets\OpsActionOverviewWidget;
use App\Filament\App\Widgets\SystemStatusWidget;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\ModuleRegistry;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AgencyOpsDashboardProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        Filament::setCurrentPanel('app');
    }

    public function test_dashboard_shows_hardening_action_cards_with_honest_empty_states(): void
    {
        Livewire::test(OpsActionOverviewWidget::class)
            ->assertOk()
            ->assertSee('What needs attention')
            ->assertSee('Open cross-channel Findings')
            ->assertSee('No open cross-channel Findings')
            ->assertSee('Website technical Findings')
            ->assertSee('No critical/high open Website Findings')
            ->assertSee('Recently resolved important')
            ->assertSee('No critical/high Findings resolved in the last 7 days');
    }

    public function test_dashboard_counts_cross_channel_website_and_recently_resolved_findings(): void
    {
        Finding::factory()->create([
            'category' => 'cross-channel',
            'severity' => 'medium',
            'status' => 'open',
            'source_module' => 'cross-asset-analysis',
        ]);
        Finding::factory()->create([
            'category' => 'cross-channel',
            'severity' => 'medium',
            'status' => 'resolved',
            'source_module' => 'cross-asset-analysis',
        ]);

        Finding::factory()->create([
            'category' => 'availability',
            'severity' => 'critical',
            'status' => 'open',
            'source_module' => 'website',
        ]);
        Finding::factory()->create([
            'category' => 'on-page',
            'severity' => 'low',
            'status' => 'open',
            'source_module' => 'website',
        ]);
        Finding::factory()->create([
            'category' => 'availability',
            'severity' => 'critical',
            'status' => 'open',
            'source_module' => 'search-console',
        ]);

        Finding::factory()->create([
            'category' => 'seo',
            'severity' => 'high',
            'status' => 'resolved',
            'source_module' => 'website',
            'last_seen_at' => now()->subDays(2),
        ]);
        Finding::factory()->create([
            'category' => 'seo',
            'severity' => 'high',
            'status' => 'resolved',
            'source_module' => 'website',
            'last_seen_at' => now()->subDays(10),
        ]);

        Livewire::test(OpsActionOverviewWidget::class)
            ->assertOk()
            ->assertSee('Open cross-channel Findings')
            ->assertSee('Open Findings with category cross-channel')
            ->assertSee('Website technical Findings')
            ->assertSee('Open critical/high Findings from website module')
            ->assertSee('Recently resolved important')
            ->assertSee('Critical/high Findings resolved within the last 7 days');
    }

    public function test_dashboard_page_loads_ops_and_system_widgets_after_hardening(): void
    {
        $this->get('/app')
            ->assertOk()
            ->assertSee('MoxDOP')
            ->assertSeeLivewire(OpsActionOverviewWidget::class)
            ->assertSeeLivewire(SystemStatusWidget::class);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSeeLivewire(OpsActionOverviewWidget::class)
            ->assertSeeLivewire(SystemStatusWidget::class);
    }

    public function test_production_hardening_invariants_for_core_platform_surfaces(): void
    {
        $this->get('/up')->assertOk();
        $this->assertSame('MoxDOP', config('app.name'));
        $this->assertTrue($this->app->isBooted());

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);

        $this->assertTrue($website->brand->is($brand));
        $this->assertTrue($brand->customer->is($customer));

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $website->id,
            'type' => 'ga4',
            'enabled' => true,
            'last_error' => null,
        ]);

        $secretPayload = [
            'client_id' => 'hardening-client-id',
            'client_secret' => 'hardening-client-secret',
            'refresh_token' => 'hardening-refresh-token',
        ];

        $credential = CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => $secretPayload,
        ]);

        $storedPayload = DB::table('core_connection_credentials')
            ->where('id', $credential->id)
            ->value('encrypted_payload');

        $this->assertIsString($storedPayload);
        $this->assertStringNotContainsString('hardening-client-secret', $storedPayload);
        $this->assertSame($secretPayload, $credential->fresh()->encrypted_payload);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->fresh()->toArray());

        $module = ModuleRegistry::query()->create([
            'module_id' => 'website',
            'enabled' => true,
            'installed_version' => '1.0.0',
        ]);
        $this->assertTrue(ModuleRegistry::isEnabled('website'));
        $module->update(['enabled' => false]);
        $this->assertFalse(ModuleRegistry::isEnabled('website'));

        $finding = Finding::factory()->create([
            'digital_asset_id' => $website->id,
            'category' => 'cross-channel',
            'severity' => 'high',
            'status' => 'open',
            'source_module' => 'cross-asset-analysis',
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'status' => 'open',
            'priority' => 'high',
        ]);
        $task = Task::factory()->create([
            'status' => 'open',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $this->assertTrue($finding->recommendations->contains($recommendation));
        $this->assertSame('open', $task->status);

        $teamMember = User::factory()->create();
        $teamMember->assignRole(Roles::TEAM_MEMBER);
        $this->assertTrue($teamMember->hasRole(Roles::TEAM_MEMBER));
        $this->assertFalse($teamMember->hasRole(Roles::ADMIN));

        Livewire::test(OpsActionOverviewWidget::class)
            ->assertOk()
            ->assertSee('Open cross-channel Findings')
            ->assertSee('Open Findings with category cross-channel');
    }
}
