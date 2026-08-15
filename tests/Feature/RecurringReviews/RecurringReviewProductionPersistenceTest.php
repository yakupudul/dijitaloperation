<?php

namespace Tests\Feature\RecurringReviews;

use App\Enums\PlaybookApplicabilityMode;
use App\Enums\PlaybookStatus;
use App\Enums\RecurringReviewCadence;
use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewOutcomeKind;
use App\Enums\RecurringReviewRunStatus;
use App\Enums\RecurringReviewScheduleStatus;
use App\Enums\RecurringReviewScopeKind;
use App\Enums\TaskSourceKind;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\Recommendation;
use App\Models\RecurringReviewCheckDefinition;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewSchedule;
use App\Models\Task;
use App\Models\User;
use App\Services\RecurringReviews\CompleteRecurringReviewCheck;
use App\Services\RecurringReviews\MaterializeRecurringReviewOccurrence;
use App\Services\RecurringReviews\RecurringReviewReadService;
use App\Services\RecurringReviews\RecurringReviewRunService;
use App\Services\RecurringReviews\RecurringReviewScheduleService;
use App\Services\Tasks\TaskReadService;
use App\Services\Work\WorkReadService;
use App\Support\Roles;
use App\Support\Tasks\TaskStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecurringReviewProductionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $asset;

    private Playbook $playbook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Roles::ADMIN);
        $this->actingAs($this->actor);

        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id]);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);

        $this->playbook = Playbook::factory()->create(['status' => PlaybookStatus::Active->value]);
        $revision = PlaybookRevision::factory()->create([
            'playbook_id' => $this->playbook->id,
            'service_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Any->value,
        ]);
        $this->playbook->forceFill(['current_revision_id' => $revision->id])->save();

        Http::fake();
    }

    public function test_canonical_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('recurring_review_schedules'));
        $this->assertTrue(Schema::hasTable('recurring_review_check_definitions'));
        $this->assertTrue(Schema::hasTable('recurring_review_runs'));
        $this->assertTrue(Schema::hasTable('recurring_review_run_items'));
        $this->assertTrue(Schema::hasTable('recurring_review_run_item_task_links'));
        $this->assertTrue(Schema::hasColumn('tasks', 'recurring_review_run_item_id'));
        $this->assertFalse(Schema::hasTable('works'));
    }

    public function test_create_schedule_requires_cadence_and_at_least_one_check(): void
    {
        $service = app(RecurringReviewScheduleService::class);

        try {
            $service->create([
                'customer_id' => $this->customer->id,
                'scope_kind' => RecurringReviewScopeKind::Brand->value,
                'brand_id' => $this->brand->id,
                'playbook_id' => $this->playbook->id,
                'timezone' => 'UTC',
                'starts_at' => now()->toDateTimeString(),
                'checks' => [['title' => 'Check A']],
            ], $this->actor);
            $this->fail('Expected CADENCE_REQUIRED');
        } catch (RecurringReviewValidationException $exception) {
            $this->assertSame('CADENCE_REQUIRED', $exception->errorCode);
        }

        try {
            $service->create([
                'customer_id' => $this->customer->id,
                'scope_kind' => RecurringReviewScopeKind::Brand->value,
                'brand_id' => $this->brand->id,
                'playbook_id' => $this->playbook->id,
                'cadence' => RecurringReviewCadence::Weekly->value,
                'timezone' => 'UTC',
                'starts_at' => now()->toDateTimeString(),
                'checks' => [],
            ], $this->actor);
            $this->fail('Expected validation failure for empty checks');
        } catch (RecurringReviewValidationException $exception) {
            $this->assertContains($exception->errorCode, ['VALIDATION_FAILED', 'CHECKS_REQUIRED']);
        }
    }

    public function test_create_schedule_materialize_complete_outcomes_and_idempotency(): void
    {
        $scheduleService = app(RecurringReviewScheduleService::class);

        $schedule = $scheduleService->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::DigitalAsset->value,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Weekly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'checks' => [
                ['title' => 'Landing page health', 'is_required' => true],
                ['title' => 'Tracking intact', 'is_required' => true],
            ],
        ], $this->actor, 'rr-sched-1');

        $again = $scheduleService->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::DigitalAsset->value,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Monthly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->toDateTimeString(),
            'checks' => [['title' => 'Ignored on idempotent retry']],
        ], $this->actor, 'rr-sched-1');

        $this->assertSame($schedule->id, $again->id);
        $this->assertSame(RecurringReviewScheduleStatus::Active, $schedule->status);
        $this->assertSame(2, $schedule->checkDefinitions()->where('is_active', true)->count());
        $this->assertNotNull($schedule->next_due_at);

        $dueAt = now();
        $materialize = app(MaterializeRecurringReviewOccurrence::class);
        $run = $materialize->materialize(
            $schedule,
            'scheduled:'.$dueAt->format('Y-m-d\TH:i:s'),
            $dueAt,
            RecurringReviewOccurrenceKind::Scheduled,
            $this->actor,
        );
        $runRetry = $materialize->materialize(
            $schedule,
            'scheduled:'.$dueAt->format('Y-m-d\TH:i:s'),
            $dueAt,
            RecurringReviewOccurrenceKind::Scheduled,
            $this->actor,
        );

        $this->assertSame($run->id, $runRetry->id);
        $this->assertSame(RecurringReviewRunStatus::Scheduled, $run->status);
        $this->assertSame($this->playbook->current_revision_id, $run->playbook_revision_id);
        $this->assertCount(2, $run->items);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame(0, Evidence::query()->count());

        $runService = app(RecurringReviewRunService::class);
        $run = $runService->startRun($run, $this->actor);
        $this->assertSame(RecurringReviewRunStatus::InProgress, $run->status);
        $this->assertSame($this->actor->id, $run->reviewer_user_id);

        $complete = app(CompleteRecurringReviewCheck::class);
        $items = $run->items()->orderBy('position')->get();

        $noIssue = $complete->complete($items[0], 'no_issue', [], $this->actor, 'rr-item-1');
        $this->assertSame(RecurringReviewOutcomeKind::NoIssue, $noIssue['item']->outcome_kind);
        $this->assertNotNull($noIssue['item']->evidence_id);
        $this->assertNull($noIssue['item']->finding_id);
        $this->assertSame(0, Recommendation::query()->count());

        $findingResult = $complete->complete($items[1], 'finding', [], $this->actor, 'rr-item-2');
        $this->assertSame(RecurringReviewOutcomeKind::Finding, $findingResult['item']->outcome_kind);
        $this->assertInstanceOf(Finding::class, $findingResult['finding']);
        $this->assertSame(Finding::STATUS_OPEN, $findingResult['finding']->status);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, Recommendation::query()->count());

        $findingAgain = $complete->complete($items[1]->fresh(), 'finding', [], $this->actor, 'rr-item-2');
        $this->assertSame($findingResult['finding']->id, $findingAgain['finding']->id);

        try {
            $complete->complete($items[1]->fresh(), 'opportunity', [], $this->actor);
            $this->fail('Expected CONFLICT');
        } catch (RecurringReviewValidationException $exception) {
            $this->assertSame('CONFLICT', $exception->errorCode);
        }

        $completed = $runService->completeRun($run->fresh(['items']), $this->actor);
        $this->assertSame(RecurringReviewRunStatus::Completed, $completed->status);
        $this->assertNotNull($completed->summary_json);
        $this->assertSame(1, (int) ($completed->summary_json['outcomes_finding'] ?? 0));
        $this->assertSame(1, (int) ($completed->summary_json['outcomes_no_issue'] ?? 0));

        $schedule->refresh();
        $this->assertNotNull($schedule->next_due_at);

        Http::assertNothingSent();
    }

    public function test_task_outcome_links_open_task_and_source_label(): void
    {
        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::DigitalAsset->value,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Monthly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->toDateTimeString(),
            'checks' => [['title' => 'Fix CTA']],
        ], $this->actor);

        $dueAt = now();
        $run = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:1',
            $dueAt,
            RecurringReviewOccurrenceKind::Manual,
            $this->actor,
        );
        $item = $run->items()->firstOrFail();

        $first = app(CompleteRecurringReviewCheck::class)->complete(
            $item,
            'task',
            ['title' => 'Fix CTA copy'],
            $this->actor,
            'rr-task-1',
        );

        $this->assertInstanceOf(Task::class, $first['task']);
        $this->assertSame(TaskSourceKind::RecurringReviewCheck, $first['task']->source_kind);
        $this->assertSame($item->id, $first['task']->recurring_review_run_item_id);
        $this->assertSame(TaskStatus::OPEN, $first['task']->status);

        $run2 = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:2',
            $dueAt->copy()->addWeek(),
            RecurringReviewOccurrenceKind::Manual,
            $this->actor,
        );
        $item2 = $run2->items()->firstOrFail();

        $linked = app(CompleteRecurringReviewCheck::class)->complete(
            $item2,
            'task',
            ['title' => 'Should link existing'],
            $this->actor,
            'rr-task-2',
        );

        $this->assertSame($first['task']->id, $linked['task']->id);
        $this->assertSame(1, Task::query()->count());
        $this->assertSame($first['task']->recurring_review_run_item_id, $item->id);

        $presentation = app(TaskReadService::class)->findPresentation($first['task']->id);
        $this->assertSame('recurring_review_check', $presentation['source']);
        $this->assertSame('Recurring Review', $presentation['source_label']);
    }

    public function test_pause_resume_and_due_schedules_do_not_materialize(): void
    {
        $service = app(RecurringReviewScheduleService::class);
        $schedule = $service->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::Brand->value,
            'brand_id' => $this->brand->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Weekly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->subWeek()->toDateTimeString(),
            'checks' => [['title' => 'Brand pulse']],
        ], $this->actor);

        $service->pause($schedule, $this->actor);
        $this->assertSame(RecurringReviewScheduleStatus::Paused, $schedule->fresh()->status);
        $this->assertNull($schedule->fresh()->next_due_at);

        $resumed = $service->resume($schedule->fresh(), $this->actor);
        $this->assertSame(RecurringReviewScheduleStatus::Active, $resumed->status);
        $this->assertNotNull($resumed->next_due_at);

        $beforeRuns = RecurringReviewRun::query()->count();
        $due = app(RecurringReviewReadService::class)->dueSchedules(now()->addYear());
        $this->assertNotEmpty($due);
        $this->assertSame($beforeRuns, RecurringReviewRun::query()->count());
    }

    public function test_work_aggregate_uses_production_runs_not_demo_ids(): void
    {
        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::Brand->value,
            'brand_id' => $this->brand->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Weekly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->toDateTimeString(),
            'checks' => [['title' => 'Work visible']],
        ], $this->actor);

        $run = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'scheduled:work',
            now()->subHour(),
            RecurringReviewOccurrenceKind::Scheduled,
            $this->actor,
        );

        $items = collect(app(WorkReadService::class)->workItems());
        $reviewRows = $items->where('type', 'recurring_review');

        $this->assertTrue($reviewRows->contains(fn (array $row): bool => (string) $row['id'] === (string) $run->id));
        $this->assertFalse($reviewRows->contains(fn (array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'rr-')));
        $this->assertTrue($reviewRows->every(fn (array $row): bool => ($row['source_state'] ?? null) === 'REAL'));
    }

    public function test_archived_playbook_blocks_materialization(): void
    {
        $schedule = RecurringReviewSchedule::factory()->create([
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'playbook_id' => $this->playbook->id,
            'scope_kind' => RecurringReviewScopeKind::Brand->value,
        ]);
        RecurringReviewCheckDefinition::factory()->create(['schedule_id' => $schedule->id]);

        $this->playbook->forceFill(['status' => PlaybookStatus::Archived->value])->save();

        try {
            app(MaterializeRecurringReviewOccurrence::class)->materialize(
                $schedule,
                'scheduled:blocked',
                now(),
            );
            $this->fail('Expected PLAYBOOK_UNAVAILABLE');
        } catch (RecurringReviewValidationException $exception) {
            $this->assertSame('PLAYBOOK_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_update_checks_does_not_rewrite_historical_run_items(): void
    {
        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::Brand->value,
            'brand_id' => $this->brand->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Weekly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->toDateTimeString(),
            'checks' => [['title' => 'Original']],
        ], $this->actor);

        $run = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'scheduled:hist',
            now(),
        );
        $historicalTitle = $run->items()->firstOrFail()->title_snapshot;

        app(RecurringReviewScheduleService::class)->updateChecks($schedule, [
            ['title' => 'Replacement'],
        ], $this->actor);

        $this->assertSame($historicalTitle, $run->items()->firstOrFail()->fresh()->title_snapshot);
        $this->assertSame(1, RecurringReviewCheckDefinition::query()->where('schedule_id', $schedule->id)->where('is_active', true)->count());
        $this->assertSame('Replacement', RecurringReviewCheckDefinition::query()->where('schedule_id', $schedule->id)->where('is_active', true)->value('title'));
    }

    public function test_finding_dedup_across_runs_and_no_issue_does_not_resolve(): void
    {
        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::DigitalAsset->value,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Monthly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->toDateTimeString(),
            'checks' => [['title' => 'Persistent issue']],
        ], $this->actor);

        $run1 = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:find-1',
            now(),
            RecurringReviewOccurrenceKind::Manual,
            $this->actor,
        );
        $item1 = $run1->items()->firstOrFail();
        $first = app(CompleteRecurringReviewCheck::class)->complete($item1, 'finding', [], $this->actor, 'find-1');
        $this->assertSame(1, Finding::query()->count());

        $run2 = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:find-2',
            now()->addMonth(),
            RecurringReviewOccurrenceKind::Manual,
            $this->actor,
        );
        $item2 = $run2->items()->firstOrFail();
        $second = app(CompleteRecurringReviewCheck::class)->complete($item2, 'finding', [], $this->actor, 'find-2');
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame($first['finding']->id, $second['finding']->id);
        $this->assertSame(Finding::STATUS_OPEN, $second['finding']->fresh()->status);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, Recommendation::query()->count());

        $run3 = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:find-3',
            now()->addMonths(2),
            RecurringReviewOccurrenceKind::Manual,
            $this->actor,
        );
        $item3 = $run3->items()->firstOrFail();
        app(CompleteRecurringReviewCheck::class)->complete($item3, 'no_issue', [], $this->actor, 'find-3');
        $this->assertSame(Finding::STATUS_OPEN, Finding::query()->firstOrFail()->status);
        $this->assertGreaterThanOrEqual(2, Evidence::query()->count());
    }

    public function test_invalid_brand_and_first_brand_fallback_rejected(): void
    {
        $otherCustomer = Customer::factory()->create();
        $foreignBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);

        try {
            app(RecurringReviewScheduleService::class)->create([
                'customer_id' => $this->customer->id,
                'scope_kind' => RecurringReviewScopeKind::Brand->value,
                'brand_id' => $foreignBrand->id,
                'playbook_id' => $this->playbook->id,
                'cadence' => RecurringReviewCadence::Weekly->value,
                'timezone' => 'UTC',
                'starts_at' => now()->toDateTimeString(),
                'checks' => [['title' => 'Bad brand']],
            ], $this->actor);
            $this->fail('Expected hierarchy validation');
        } catch (RecurringReviewValidationException $exception) {
            $this->assertNotSame('', $exception->errorCode);
        }

        try {
            app(RecurringReviewScheduleService::class)->create([
                'customer_id' => $this->customer->id,
                'scope_kind' => RecurringReviewScopeKind::Brand->value,
                'brand_id' => null,
                'playbook_id' => $this->playbook->id,
                'cadence' => RecurringReviewCadence::Weekly->value,
                'timezone' => 'UTC',
                'starts_at' => now()->toDateTimeString(),
                'checks' => [['title' => 'Missing brand']],
            ], $this->actor);
            $this->fail('Expected scope shape rejection');
        } catch (RecurringReviewValidationException $exception) {
            $this->assertNotSame('', $exception->errorCode);
        }

        $this->assertSame(0, RecurringReviewSchedule::query()->count());
    }

    public function test_no_scheduler_registration_in_prompt_46(): void
    {
        $console = file_get_contents(base_path('routes/console.php')) ?: '';
        $this->assertStringNotContainsString('RecurringReview', $console);
        $this->assertStringNotContainsString('MaterializeRecurringReview', $console);

        $migration = file_get_contents(database_path('migrations/2026_08_15_220000_create_recurring_review_tables.php')) ?: '';
        $this->assertStringNotContainsString('Schedule::', $migration);
        $this->assertTrue(class_exists(MaterializeRecurringReviewOccurrence::class));
    }

    public function test_review_completion_allows_open_downstream_objects(): void
    {
        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $this->customer->id,
            'scope_kind' => RecurringReviewScopeKind::DigitalAsset->value,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'playbook_id' => $this->playbook->id,
            'cadence' => RecurringReviewCadence::Weekly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->toDateTimeString(),
            'checks' => [
                ['title' => 'Finding check'],
                ['title' => 'Task check'],
            ],
        ], $this->actor);

        $run = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:complete-open',
            now(),
            RecurringReviewOccurrenceKind::Manual,
            $this->actor,
        );
        app(RecurringReviewRunService::class)->startRun($run, $this->actor);
        $items = $run->items()->orderBy('position')->get();

        app(CompleteRecurringReviewCheck::class)->complete($items[0], 'finding', [], $this->actor, 'c-find');
        app(CompleteRecurringReviewCheck::class)->complete($items[1], 'task', ['title' => 'Ops fix'], $this->actor, 'c-task');

        $completed = app(RecurringReviewRunService::class)->completeRun($run->fresh(['items']), $this->actor);
        $this->assertSame(RecurringReviewRunStatus::Completed, $completed->status);
        $this->assertSame(Finding::STATUS_OPEN, Finding::query()->firstOrFail()->status);
        $this->assertSame(TaskStatus::OPEN, Task::query()->firstOrFail()->status);
    }
}
