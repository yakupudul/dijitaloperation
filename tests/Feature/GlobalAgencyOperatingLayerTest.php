<?php

namespace Tests\Feature;

use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Operations\TasksIndex;
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
use Tests\TestCase;

class GlobalAgencyOperatingLayerTest extends TestCase
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

    public function test_operator_navigation_exposes_system_group_without_modules(): void
    {
        $labels = collect(OperatorMenu::groups())
            ->flatMap(fn (array $group): array => array_column($group['items'], 'name'))
            ->all();

        $this->assertContains('Dashboard', $labels);
        $this->assertContains('Customers', $labels);
        $this->assertContains('Digital Assets', $labels);
        $this->assertContains('Findings', $labels);
        $this->assertContains('Recommendations', $labels);
        $this->assertContains('Work', $labels);
        $this->assertContains('Opportunities', $labels);
        $this->assertContains('Activity', $labels);
        $this->assertNotContains('Tasks', $labels);
        $this->assertContains('Integrations', $labels);
        $this->assertContains('Settings', $labels);
        $this->assertNotContains('Modules', $labels);
        $this->assertNotContains('Agents', $labels);
        $this->assertNotContains('Run Registry', $labels);

        $groupTitles = array_column(OperatorMenu::groups(), 'title');
        $this->assertContains('System', $groupTitles);
        $this->assertNotContains('Data', $groupTitles);
    }

    public function test_dashboard_my_work_and_agency_modes(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSee(__('operator.dashboard_exec.needs_attention'))
            ->assertSee('Investigate lead measurement')
            ->assertSee(__('operator.dashboard_exec.recent_outcomes'))
            ->assertDontSee('Agency Health')
            ->assertDontSee('total Website visitors')
            ->call('setMode', 'agency')
            ->assertSet('mode', 'agency')
            ->assertSee('Google Integration needs attention');
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
            ->assertSee('Data Stale / Unavailable')
            ->call('setQuickView', 'data_issues')
            ->assertSee('Atlas Dental — GA4');
    }

    public function test_google_integration_bind_and_disconnect_impact_are_demo_safe(): void
    {
        Livewire::test(GoogleIntegrationPage::class)
            ->assertSee('Dependent Digital Assets')
            ->assertSee('14')
            ->call('setTab', 'resources')
            ->assertSee('Panorama Ankara GA4')
            ->call('bindResource', 'ga4-panorama')
            ->assertSee('Bound in this Demo session')
            ->call('openDisconnect')
            ->assertSee('Disconnect Google?')
            ->assertSee('Total dependent Digital Assets')
            ->call('confirmDisconnectAction')
            ->assertSee('not executed');
    }

    public function test_recommendation_accept_and_create_task_remain_internal(): void
    {
        Livewire::test(RecommendationsIndex::class)
            ->assertSee('Review conversion mapping')
            ->call('approve', 'r-review-conversion-mapping')
            ->assertSee('accepted')
            ->call('createTask', 'r-review-conversion-mapping');

        $tasks = collect(DemoState::all()['tasks']);
        $this->assertTrue($tasks->contains(fn (array $task): bool => ($task['recommendation_id'] ?? null) === 'r-review-conversion-mapping'));
    }

    public function test_tasks_default_to_my_tasks_view(): void
    {
        Livewire::test(TasksIndex::class)
            ->assertSet('view', 'my')
            ->assertSee('Investigate lead measurement')
            ->call('setView', 'all')
            ->assertSee('Update positioning language');
    }

    public function test_settings_sections_exclude_integrations_and_modules_dump(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertSee('General')
            ->call('setSection', 'team')
            ->assertSee('Ayşe Demir')
            ->call('setSection', 'ai')
            ->assertSee('Connected AI providers do not auto-accept')
            ->call('setSection', 'advanced')
            ->assertSee('Reset Demo Mode')
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
        $this->get(route('demo.customer', ['customerId' => DemoCatalog::CUSTOMER_ID]))
            ->assertOk()
            ->assertSee('Account Owner')
            ->assertSee('Ayşe Demir');

        $this->get(route('demo.customer', [
            'customerId' => DemoCatalog::CUSTOMER_ID,
            'tab' => 'contacts',
        ]))
            ->assertOk()
            ->assertSee('Dr. Elif Arslan')
            ->assertSee('Burak Şen');
    }
}
