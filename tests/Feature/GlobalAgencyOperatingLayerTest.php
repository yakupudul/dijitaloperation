<?php

namespace Tests\Feature;

use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\SettingsPage;
use App\Models\Recommendation;
use App\Models\Task;
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
        // Prompt 67/68: Dashboard lists production WorkReadService rows (seeded canonical tasks here),
        // not Demo Atlas portfolio/attention fixtures.
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee(__('operator.dashboard_exec.needs_attention'))
            ->assertSee('Investigate lead measurement')
            ->assertDontSee('Lead measurement finding open on Google Ads')
            ->assertDontSee('1 overdue recurring review (Meta Creative)')
            ->assertSee(__('operator.dashboard_exec.recent_outcomes'))
            ->assertDontSee('Agency Health')
            ->assertDontSee('total Website visitors')
            ->call('setMode', 'agency')
            ->assertSet('mode', 'agency')
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
            ->assertSee('Dependent Digital Assets')
            ->assertSee('Not configured')
            ->assertDontSee('Panorama Ankara GA4')
            ->call('setTab', 'resources')
            ->assertSee('No resources discovered yet')
            ->call('bindResource', '1')
            ->assertSee('Select a discovered Google resource to bind.')
            ->assertDontSee('Revoke Google access…');
    }

    public function test_recommendation_accept_and_create_task_remain_internal(): void
    {
        $recommendation = Recommendation::factory()->create([
            'title' => 'Review conversion mapping for primary lead signal',
            'status' => Recommendation::STATUS_OPEN,
            'digital_asset_id' => $this->workAsset->id,
        ]);
        $id = (string) $recommendation->id;

        Livewire::test(RecommendationsIndex::class)
            ->assertSee('Review conversion mapping for primary lead signal')
            ->call('approve', $id)
            ->assertSee('accepted')
            ->call('createTask', $id)
            ->assertSee('created from Recommendation');

        $this->assertSame(Recommendation::STATUS_ACCEPTED, $recommendation->fresh()->status);
        $this->assertSame(1, Task::query()->where('recommendation_id', $recommendation->id)->count());
    }

    public function test_tasks_default_to_my_tasks_view(): void
    {
        Task::query()
            ->where('title', 'Investigate lead measurement')
            ->update(['assignee_id' => auth()->id()]);

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
        $this->get(route('demo.customer', ['customerId' => $this->workCustomer->id]))
            ->assertOk()
            ->assertSee('Account Owner')
            ->assertSee('Atlas Health Group');

        $this->get(route('demo.customer', [
            'customerId' => $this->workCustomer->id,
            'tab' => 'contacts',
        ]))
            ->assertOk()
            ->assertDontSee('Dr. Elif Arslan');
    }
}
