<?php

namespace Tests\Feature;

use App\Filament\App\Widgets\OpsActionOverviewWidget;
use App\Models\CoreConnection;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgencyOpsDashboardActionCardsTest extends TestCase
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

    public function test_dashboard_shows_honest_empty_action_cards(): void
    {
        Livewire::test(OpsActionOverviewWidget::class)
            ->assertOk()
            ->assertSee('What needs attention')
            ->assertSee('All clear')
            ->assertSee('No issues currently require attention')
            ->assertDontSee('Critical open Findings')
            ->assertDontSee('Empty means nothing queued');
    }

    public function test_dashboard_counts_live_domain_records_without_fake_kpis(): void
    {
        Finding::factory()->create([
            'severity' => 'critical',
            'status' => 'open',
        ]);
        Finding::factory()->create([
            'severity' => 'low',
            'status' => 'open',
        ]);
        Finding::factory()->create([
            'severity' => 'critical',
            'status' => 'resolved',
        ]);

        Recommendation::factory()->create([
            'status' => 'open',
            'priority' => 'high',
        ]);
        Recommendation::factory()->create([
            'status' => 'dismissed',
            'priority' => 'high',
        ]);

        CoreConnection::factory()->create([
            'last_error' => 'token_expired',
        ]);
        CoreConnection::factory()->create([
            'last_error' => null,
        ]);

        Task::factory()->create([
            'status' => 'open',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        Task::factory()->create([
            'status' => 'completed',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        Livewire::test(OpsActionOverviewWidget::class)
            ->assertOk()
            ->assertSee('Critical open Findings')
            ->assertSee('1')
            ->assertSee('Open Recommendations')
            ->assertSee('Connections with errors')
            ->assertSee('1 overdue')
            ->assertDontSee('All clear');
    }

    public function test_authenticated_dashboard_page_loads_action_widget(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('MoxDOP')
            ->assertSeeLivewire(OpsActionOverviewWidget::class);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSeeLivewire(OpsActionOverviewWidget::class);
    }
}
