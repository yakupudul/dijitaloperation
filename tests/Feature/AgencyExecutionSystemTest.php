<?php

namespace Tests\Feature;

use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Models\Task;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\OpportunityFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsCanonicalWorkTasks;
use Tests\TestCase;

class AgencyExecutionSystemTest extends TestCase
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

    public function test_work_index_defaults_to_my_view(): void
    {
        Task::query()
            ->where('title', 'Investigate lead measurement')
            ->update(['assignee_id' => auth()->id()]);

        Livewire::test(TasksIndex::class)
            ->assertSet('view', 'my')
            ->assertSee('Investigate lead measurement');
    }

    public function test_global_capture_visible_in_layout(): void
    {
        $this->get(route('operator.dashboard'))
            ->assertOk()
            ->assertSee(__('operator.capture.open'));

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'note')
            ->assertSet('open', true)
            ->set('title', 'Decision note from standup')
            ->call('save')
            ->assertSet('open', true);

        $notes = DemoState::all()['capture_notes'] ?? [];
        $this->assertSame([], $notes);
    }

    public function test_dashboard_execution_sections(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSee(__('operator.dashboard_exec.today'))
            ->assertSee(__('operator.dashboard_exec.needs_attention'))
            ->assertSee(__('operator.capacity.title'))
            ->assertSee(__('operator.dashboard_exec.portfolio_focus'))
            ->assertSee(__('operator.dashboard_exec.recent_outcomes'));
    }

    public function test_routes_under_app_not_system(): void
    {
        foreach ([
            route('operator.tasks'),
            route('operator.work.show', ['workId' => '1', 'type' => 'task']),
            route('operator.customer', ['customerId' => DemoCatalog::CUSTOMER_ID]),
        ] as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $this->assertDoesNotMatchRegularExpression('#^/(app|system)(/|$)#', $path, $url);
        }

        $this->assertStringNotContainsString('/system', route('operator.tasks'));
    }

    public function test_opportunities_queue_route_is_available_without_demo_fallback(): void
    {
        // Residual Demo fixture catalog may still exist for specialist overview cards,
        // but production Operations / Dashboard growth surfaces are DB-backed and empty
        // when no canonical Opportunities exist.
        $this->assertNotEmpty(OpportunityFixtures::all());

        $this->get(route('operator.opportunities'))
            ->assertOk()
            ->assertSee(__('operator.nav.opportunities'))
            ->assertDontSee('High paid implant demand but weak organic coverage');
    }
}
