<?php

namespace Tests\Feature;

use App\Enums\ClientRequestStatus;
use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewRunStatus;
use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Livewire\Demo\Operations\WorkShow;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Settings\PlaybookShow;
use App\Livewire\Demo\SettingsPage;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Opportunity;
use App\Models\Playbook;
use App\Models\Recommendation;
use App\Models\ServiceDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\ClientRequests\CreateClientRequest;
use App\Services\ClientRequests\CreateTaskFromClientRequest;
use App\Services\Playbooks\SeedDefaultPlaybooks;
use App\Services\Qa\QaService;
use App\Services\RecurringReviews\MaterializeRecurringReviewOccurrence;
use App\Services\RecurringReviews\RecurringReviewScheduleService;
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
        app(SeedDefaultPlaybooks::class)->seed($user);
    }

    public function test_work_index_defaults_to_my_view(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads']);
        $playbook = Playbook::query()->where('stable_key', 'pb-weekly-gads')->firstOrFail();

        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $customer->id,
            'scope_kind' => 'digital_asset',
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'playbook_id' => $playbook->id,
            'cadence' => 'weekly',
            'timezone' => 'UTC',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'checks' => [['title' => 'Confirm conversion signal']],
        ], auth()->user(), 'agency-rr-sched');

        app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:agency-exec',
            now(),
            RecurringReviewOccurrenceKind::Manual,
            auth()->user(),
        );

        Task::query()
            ->where('title', 'Investigate lead measurement')
            ->update(['assignee_id' => auth()->id()]);

        Livewire::test(TasksIndex::class)
            ->assertSet('view', 'my')
            ->assertSee('Investigate lead measurement')
            ->call('setView', 'client_requests')
            ->assertDontSee("Update doctor's title on homepage")
            ->call('setView', 'recurring_reviews')
            ->assertSee('Weekly Google Ads Review');
    }

    public function test_client_request_capture_status_and_create_task(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'client_request', (string) $brand->id, (string) $customer->id)
            ->set('title', 'New homepage banner')
            ->call('save');

        $this->assertTrue(
            ClientRequest::query()->where('title', 'New homepage banner')->exists()
        );

        $request = app(CreateClientRequest::class)->create([
            'title' => "Update doctor's title on homepage",
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
        ], auth()->user());

        Livewire::test(CustomerDetail::class, ['customerId' => (string) $customer->id])
            ->call('setTab', 'requests')
            ->assertSee("Update doctor's title on homepage")
            ->call('planRequest', (string) $request->id);

        $this->assertSame(
            ClientRequestStatus::Planned,
            $request->fresh()->status
        );

        $task = app(CreateTaskFromClientRequest::class)->create(
            $request->fresh(),
            [],
            auth()->user(),
            'agency-exec-create-task',
        );
        $this->assertSame($request->id, $task->client_request_id);
    }

    public function test_playbooks_settings_catalog_and_detail(): void
    {
        $url = route('operator.settings', ['section' => 'operations', 'ops_sub' => 'playbooks']);
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
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads']);
        $playbook = Playbook::query()->where('stable_key', 'pb-weekly-gads')->firstOrFail();

        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $customer->id,
            'scope_kind' => 'digital_asset',
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'playbook_id' => $playbook->id,
            'cadence' => 'weekly',
            'timezone' => 'UTC',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'checks' => [
                ['title' => 'Confirm conversion signal'],
                ['title' => 'Review waste'],
            ],
        ], auth()->user(), 'agency-rr-complete');

        $run = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:agency-complete',
            now(),
            RecurringReviewOccurrenceKind::Manual,
            auth()->user(),
        );

        Livewire::test(WorkShow::class, ['workId' => (string) $run->id, 'type' => 'recurring_review'])
            ->call('completeReview', 'no_issue');

        $this->assertSame(
            RecurringReviewRunStatus::Completed,
            $run->fresh()->status
        );
        $this->assertSame(0, Task::query()->where('recurring_review_run_item_id', '!=', null)->count());
        $this->assertSame(0, Opportunity::query()->count());

        $run2 = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:agency-opp',
            now()->addDay(),
            RecurringReviewOccurrenceKind::Manual,
            auth()->user(),
        );

        Livewire::test(WorkShow::class, ['workId' => (string) $run2->id, 'type' => 'recurring_review'])
            ->call('completeReview', 'opportunity');

        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
    }

    public function test_approval_waiting_and_approve(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $task = Task::factory()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'title' => 'Landing copy for client',
            'status' => 'open',
            'recommendation_id' => null,
            'source_kind' => 'direct',
            'scope_kind' => 'brand',
            'digital_asset_id' => null,
        ]);
        $approval = app(ApprovalService::class)->request(
            $task,
            ['kind' => 'client'],
            auth()->user(),
            'agency-exec-approval:'.$task->id,
        );

        Livewire::test(WorkShow::class, ['workId' => (string) $approval->id, 'type' => 'approval'])
            ->assertSee('Client approval')
            ->call('approve');

        $approval->refresh();
        $this->assertSame('decided', $approval->status->value);
        $this->assertSame('approved', $approval->decision->value);
    }

    public function test_qa_ready_and_approve(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $task = Task::factory()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'title' => 'Replace creative',
            'status' => 'in_progress',
            'recommendation_id' => null,
            'source_kind' => 'direct',
            'scope_kind' => 'brand',
            'digital_asset_id' => null,
        ]);
        app(QaService::class)->requestReview($task, [], auth()->user(), 'agency-exec-qa:'.$task->id);

        Livewire::test(TasksIndex::class)
            ->call('setView', 'qa_required')
            ->assertSet('view', 'qa_required')
            ->assertSee('Replace creative');

        Livewire::test(WorkShow::class, ['workId' => (string) $task->id, 'type' => 'task'])
            ->call('approveQa');

        $this->assertDatabaseHas('qa_reviews', [
            'task_id' => $task->id,
            'status' => 'completed',
            'result' => 'passed',
        ]);
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
        $this->get(route('operator.dashboard'))
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

        // Prompt 42–46 productionized requests/tasks/approvals/qa/playbooks/recurring reviews.
        $this->assertTrue(Schema::hasTable('client_requests'));
        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertTrue(Schema::hasTable('approvals'));
        $this->assertTrue(Schema::hasTable('qa_reviews'));
        $this->assertTrue(Schema::hasTable('playbooks'));
        $this->assertTrue(Schema::hasTable('playbook_revisions'));
        $this->assertTrue(Schema::hasTable('recurring_review_schedules'));
        $this->assertTrue(Schema::hasTable('recurring_review_runs'));

        foreach (['recurring_reviews', 'works'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Forbidden table {$table} should not exist.");
            $this->assertStringNotContainsString("create('{$table}'", $migrationContents);
            $this->assertStringNotContainsString("create(\"{$table}\"", $migrationContents);
        }
    }

    public function test_routes_under_app_not_system(): void
    {
        $this->assertStringContainsString('/app', route('operator.tasks'));
        $this->assertStringContainsString('/app', route('operator.work.show', ['workId' => 'req-1', 'type' => 'client_request']));
        $this->assertStringContainsString('/app', route('operator.settings.playbook', ['playbookId' => 'pb-weekly-gads']));
        $this->assertStringContainsString('/app', route('operator.customer', ['customerId' => DemoCatalog::CUSTOMER_ID]));

        $this->assertStringNotContainsString('/system', route('operator.tasks'));
    }

    public function test_instagram_outside_scope_request_still_present(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $service = ServiceDefinition::query()->where('code', 'meta_ads')->firstOrFail();

        app(CreateClientRequest::class)->create([
            'title' => 'Daily Instagram posting',
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'service_definition_id' => $service->id,
        ], auth()->user());

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

        $this->get(route('operator.opportunities'))
            ->assertOk()
            ->assertSee(__('operator.nav.opportunities'))
            ->assertDontSee('High paid implant demand but weak organic coverage');
    }
}
