<?php

namespace Tests\Feature;

use App\Livewire\Demo\Dashboard;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
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

    public function test_dashboard_execution_sections(): void
    {
        // Step 3: Bugün = operational brands with their top card; Komuta merkezi / Portföy sağlığı left the home screen.
        Livewire::test(Dashboard::class)
            ->assertSee(__('operator.dashboard_exec.today'))
            ->assertDontSee(__('operator.dashboard_exec.weekly_top'))
            ->assertDontSee('Portföy sağlığı')
            ->assertDontSee(__('operator.capacity.title'))
            ->assertDontSee(__('operator.dashboard_exec.portfolio_focus'));
    }

    public function test_routes_under_app_not_system(): void
    {
        foreach ([
            route('operator.dashboard'),
            route('operator.library.queries'),
            route('operator.customer', ['customerId' => DemoCatalog::CUSTOMER_ID]),
        ] as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $this->assertDoesNotMatchRegularExpression('#^/(app|system)(/|$)#', $path, $url);
        }

        $this->assertStringNotContainsString('/system', route('operator.library.queries'));
    }
}
