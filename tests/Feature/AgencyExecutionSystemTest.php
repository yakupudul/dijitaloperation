<?php

namespace Tests\Feature;

use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Livewire\Demo\Operations\WorkShow;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Settings\PlaybookShow;
use App\Livewire\Demo\SettingsPage;
use App\Models\User;
use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\OpportunityFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AgencyExecutionSystemTest extends TestCase
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

    public function test_work_index_defaults_to_my_view(): void
    {
        Livewire::test(TasksIndex::class)
            ->assertSet('view', 'my')
            ->assertSee('Investigate lead measurement')
            ->call('setView', 'client_requests')
            ->assertSee("Update doctor's title on homepage")
            ->call('setView', 'recurring_reviews')
            ->assertSee('Weekly Google Ads Review');
    }

    public function test_client_request_capture_status_and_create_task(): void
    {
        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'client_request')
            ->set('title', 'New homepage banner')
            ->call('save');

        $requests = DemoState::clientRequestsWithState();
        $this->assertTrue(collect($requests)->contains(fn (array $r): bool => ($r['title'] ?? '') === 'New homepage banner'));

        Livewire::test(CustomerDetail::class, ['customerId' => DemoCatalog::CUSTOMER_ID])
            ->call('setTab', 'requests')
            ->assertSee("Update doctor's title on homepage")
            ->call('planRequest', 'req-doctor-title');

        $request = DemoState::findClientRequest('req-doctor-title');
        $this->assertSame('planned', $request['status'] ?? null);

        DemoState::createTaskFromClientRequest('req-doctor-title');
        $request = DemoState::findClientRequest('req-doctor-title');
        $this->assertNotNull($request['linked_task_id'] ?? null);
    }

    public function test_playbooks_settings_catalog_and_detail(): void
    {
        $url = route('demo.settings', ['section' => 'operations', 'ops_sub' => 'playbooks']);
        $this->assertStringContainsString('/app', $url);

        Livewire::test(SettingsPage::class, ['section' => 'operations', 'ops_sub' => 'playbooks'])
            ->assertSee('Weekly Google Ads Review')
            ->assertSee('Monthly SEO Coverage Review');

        Livewire::test(PlaybookShow::class, ['playbookId' => 'pb-weekly-gads'])
            ->assertSee('Weekly Google Ads Review')
            ->assertSee('Confirm primary conversion signal is firing');
    }

    public function test_recurring_review_complete_no_issue_and_opportunity(): void
    {
        Livewire::test(WorkShow::class, ['workId' => 'rr-gads-aug13', 'type' => 'recurring_review'])
            ->call('completeReview', 'no_issue');

        $reviews = DemoState::recurringReviewsWithState();
        $review = collect($reviews)->firstWhere('id', 'rr-gads-aug13');
        $this->assertSame('completed', $review['status'] ?? null);

        DemoState::reset();
        DemoState::completeRecurringReview('rr-seo-aug14', 'opportunity');

        $hypotheses = DemoState::hypothesesWithStatus();
        $this->assertNotEmpty($hypotheses);
    }

    public function test_approval_waiting_and_approve(): void
    {
        Livewire::test(WorkShow::class, ['workId' => 'appr-landing-copy', 'type' => 'approval'])
            ->assertSee('Client approval')
            ->call('approve');

        $states = DemoState::all()['approval_states'] ?? [];
        $this->assertSame('approved', $states['appr-landing-copy']['status'] ?? null);
    }

    public function test_qa_ready_and_approve(): void
    {
        Livewire::test(TasksIndex::class)
            ->call('setView', 'qa_required')
            ->assertSee('Replace PB-Video-03 creative');

        DemoState::setQaState('t-replace-creative', 'approved');
        $qa = DemoState::all()['qa_states'] ?? [];
        $this->assertSame('approved', $qa['t-replace-creative'] ?? null);
    }

    public function test_team_capacity_has_transparent_label_not_magic_score(): void
    {
        $capacity = AgencyExecutionFixtures::teamCapacity();

        $this->assertArrayHasKey('label', $capacity);
        $this->assertArrayHasKey('thresholds', $capacity);
        $this->assertContains($capacity['label'], ['Light', 'Balanced', 'Heavy', 'Overloaded']);
        $this->assertArrayNotHasKey('score', $capacity);
    }

    public function test_global_capture_visible_in_layout(): void
    {
        $this->get(route('demo.dashboard'))
            ->assertOk()
            ->assertSee(__('operator.capture.open'));

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'note')
            ->assertSet('open', true)
            ->set('title', 'Decision note from standup')
            ->call('save');

        $notes = DemoState::all()['capture_notes'] ?? [];
        $this->assertNotEmpty($notes);
    }

    public function test_dashboard_execution_sections(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSee(__('operator.dashboard_exec.today'))
            ->assertSee(__('operator.dashboard_exec.needs_attention'))
            ->assertSee(__('operator.capacity.title'))
            ->assertSee(__('operator.dashboard_exec.recurring_reviews'))
            ->assertSee(__('operator.dashboard_exec.portfolio_focus'))
            ->assertSee(__('operator.dashboard_exec.recent_outcomes'));
    }

    public function test_no_production_tables_for_execution_entities(): void
    {
        $migrationContents = collect(File::allFiles(database_path('migrations')))
            ->map(fn ($file) => File::get($file->getPathname()))
            ->implode("\n");

        foreach (['client_requests', 'playbooks', 'recurring_reviews', 'approvals', 'qa_reviews'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Production table {$table} should not exist.");
            $this->assertStringNotContainsString("create('{$table}'", $migrationContents);
            $this->assertStringNotContainsString("create(\"{$table}\"", $migrationContents);
        }
    }

    public function test_routes_under_app_not_system(): void
    {
        $this->assertStringContainsString('/app', route('demo.tasks'));
        $this->assertStringContainsString('/app', route('demo.work.show', ['workId' => 'req-1', 'type' => 'client_request']));
        $this->assertStringContainsString('/app', route('demo.settings.playbook', ['playbookId' => 'pb-weekly-gads']));
        $this->assertStringContainsString('/app', route('demo.customer', ['customerId' => DemoCatalog::CUSTOMER_ID]));

        $this->assertStringNotContainsString('/system', route('demo.tasks'));
    }

    public function test_instagram_outside_scope_request_still_present(): void
    {
        Livewire::test(TasksIndex::class)
            ->call('setView', 'client_requests')
            ->assertSee('Daily Instagram posting')
            ->assertSee(__('operator.commercial.outside_scope'));
    }

    public function test_opportunities_queue_route_is_available_without_demo_fallback(): void
    {
        // Residual Demo fixture catalog may still exist for specialist overview cards,
        // but production Operations / Dashboard growth surfaces are DB-backed and empty
        // when no canonical Opportunities exist.
        $this->assertNotEmpty(OpportunityFixtures::all());

        $this->get(route('demo.opportunities'))
            ->assertOk()
            ->assertSee(__('operator.nav.opportunities'))
            ->assertDontSee('High paid implant demand but weak organic coverage');
    }
}
