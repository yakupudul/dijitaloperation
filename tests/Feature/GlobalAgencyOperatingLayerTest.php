<?php

namespace Tests\Feature;

use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\SettingsPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use App\Support\OperatorMenu;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsCanonicalWorkTasks;
use Tests\TestCase;

class GlobalAgencyOperatingLayerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCanonicalWorkTasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
        $this->seedCanonicalWorkTasks();
    }

    public function test_operator_navigation_exposes_system_group_without_modules(): void
    {
        $labels = collect(OperatorMenu::groups())
            ->flatMap(fn (array $group): array => array_column($group['items'], 'name'))
            ->all();

        $this->assertContains('Today', $labels);
        $this->assertContains('Customers', $labels);
        $this->assertContains('Queries', $labels, 'v2: Sorgular in the sidebar');
        $this->assertNotContains('Digital Assets', $labels, 'Step 3: assets are reached from Entegrasyonlar / brand Ayarlar');
        $this->assertNotContains('Findings', $labels, 'Faz 10e sade menü');
        $this->assertNotContains('Recommendations', $labels);
        $this->assertNotContains('Command Center', $labels, 'Step 3: daily work starts from Bugün → brand workspace');
        $this->assertNotContains('Opportunities', $labels);
        $this->assertNotContains('Activity', $labels, 'W7: Activity is a tab of Settings');
        $this->assertNotContains('Tasks', $labels);
        $this->assertContains('Integrations', $labels);
        $this->assertContains('Settings', $labels);
        $this->assertNotContains('Modules', $labels);
        $this->assertNotContains('Agents', $labels);
        $this->assertNotContains('Run Registry', $labels);

        $groupTitles = array_column(OperatorMenu::groups(), 'title');
        $this->assertCount(1, $groupTitles, 'v2: one sidebar group');
        $this->assertNotContains('Data', $groupTitles);
    }

    public function test_dashboard_shows_the_command_center_top_list_not_demo_fixtures(): void
    {
        // W5: the dashboard shows the Command Center top list; no Demo Atlas portfolio/attention fixtures.
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee(__('operator.dashboard_exec.today'))
            ->assertDontSee('Lead measurement finding open on Google Ads')
            ->assertDontSee('1 overdue recurring review (Meta Creative)')
            ->assertDontSee('Agency Health')
            ->assertDontSee('total Website visitors')
            ->assertDontSee('Google Integration needs attention');
    }

    public function test_digital_assets_have_data_and_operational_states_and_responsibility(): void
    {
        $ga4 = DemoCatalog::asset(DemoCatalog::GA4_ASSET_ID);
        $this->assertNotNull($ga4);
        $this->assertSame('ga4', $ga4['type']);
        $this->assertArrayHasKey('operational_status', $ga4);
        $this->assertArrayHasKey('data_state', $ga4);
        $this->assertNotEmpty($ga4['responsible_users'] ?? []);

        Livewire::test(AssetsIndex::class)
            ->assertSee('Atlas Dental Website')
            ->assertDontSee('Atlas Dental — GA4');
    }

    public function test_google_integration_bind_and_disconnect_are_not_fake_real(): void
    {
        Livewire::test(GoogleIntegrationPage::class)
            ->assertSee('Bağlı dijital varlıklar')
            ->assertSee('Not configured')
            ->assertDontSee('Panorama Ankara GA4')
            ->call('setTab', 'resources')
            ->assertSee('No resources discovered yet')
            ->call('bindResource', '1')
            ->assertSee('Select a discovered Google resource to bind.')
            ->assertDontSee('Revoke Google access…');
    }

    public function test_settings_sections_exclude_integrations_and_modules_dump(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertSee('General')
            ->call('setSection', 'team')
            ->assertDontSee('Ayşe Demir')
            ->assertDontSee('Selin Kaya')
            ->call('setSection', 'ai')
            ->assertSee('Provider API keys are configured under Integrations')
            ->call('setSection', 'advanced')
            ->assertDontSee('Reset Demo Mode')
            ->assertDontSee('Modules menu');
    }

    public function test_findings_and_tasks_stay_within_atlas_customer_scope(): void
    {
        foreach (DemoCatalog::findings() as $finding) {
            $this->assertSame('Atlas Dental Ankara', $finding['brand']);
        }

        foreach (DemoState::all()['tasks'] as $task) {
            $this->assertSame('Atlas Dental Ankara', $task['brand']);
        }

        $google = GlobalOperatingFixtures::googleIntegration();
        $this->assertSame(14, $google['dependent_assets']);
        $this->assertSame($google['bound'], $google['dependent_assets']);
    }

    public function test_customer_contacts_and_account_owner_surface(): void
    {
        $this->get(route('operator.customer', ['customerId' => $this->workCustomer->id]))
            ->assertOk()
            ->assertSee('Account Owner')
            ->assertSee('Atlas Health Group');

        $this->get(route('operator.customer', [
            'customerId' => $this->workCustomer->id,
            'tab' => 'contacts',
        ]))
            ->assertOk()
            ->assertDontSee('Dr. Elif Arslan');
    }
}
