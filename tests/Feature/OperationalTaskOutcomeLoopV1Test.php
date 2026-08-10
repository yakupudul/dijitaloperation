<?php

namespace Tests\Feature;

use App\Events\FindingEvaluationCompleted;
use App\Filament\App\Resources\Tasks\Pages\ListTasks;
use App\Filament\App\Resources\Tasks\Pages\ViewTask;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Findings\FindingLifecycleService;
use App\Services\Tasks\TaskLifecycleService;
use App\Services\Tasks\TaskOutcomeEvaluator;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use App\Support\Roles;
use App\Support\Tasks\TaskOutcomeStatus;
use App\Support\Tasks\TaskStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OperationalTaskOutcomeLoopV1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $websiteAsset;

    private DigitalAsset $adsAsset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        $this->websiteAsset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'name' => 'Website Asset',
        ]);

        $this->adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'name' => 'Ads Asset',
        ]);
    }

    public function test_basic_positive_improvement_loop_does_not_resolve_finding_on_task_completion(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();

        $this->assertSame(TaskStatus::COMPLETED, $task->status);
        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->outcome_status);
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertFalse(data_get($task->outcome_json, 'causal_attribution'));

        $followUpRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $followUpRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $task = $task->fresh();
        $finding = $finding->fresh();

        $this->assertSame('resolved', $finding->status);
        $this->assertSame(TaskStatus::COMPLETED, $task->status);
        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->outcome_status);
        $this->assertSame($followUpRun->id, $task->outcome_run_id);
        $this->assertSame('linked_finding_resolved', data_get($task->outcome_json, 'reason_code'));
        $this->assertSame(TaskOutcomeStatus::EVALUATOR_VERSION, data_get($task->outcome_json, 'version'));
        $this->assertFalse(data_get($task->outcome_json, 'causal_attribution'));
        $this->assertStringContainsString('does not by itself prove', (string) data_get($task->outcome_json, 'explanation'));
    }

    public function test_still_observed_when_finding_remains_open(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();

        $followUpRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply($this->matchResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $followUpRun,
            ruleId: $ruleId,
            fingerprint: $finding->fingerprint,
            observedAt: now()->addMinute(),
        ));

        $task = $task->fresh();

        $this->assertSame(TaskStatus::COMPLETED, $task->status);
        $this->assertSame(TaskOutcomeStatus::STILL_OBSERVED, $task->outcome_status);
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertNotSame('failed', $task->status);
    }

    public function test_regression_after_improvement_does_not_auto_create_task(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();

        $resolveRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $resolveRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinutes(1),
        ));

        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);

        $reopenRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply($this->matchResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $reopenRun,
            ruleId: $ruleId,
            fingerprint: $finding->fingerprint,
            observedAt: now()->addMinutes(2),
        ));

        $task = $task->fresh();

        $this->assertSame(TaskOutcomeStatus::REGRESSION_OBSERVED, $task->outcome_status);
        $this->assertSame(1, Task::query()->count());
        $this->assertSame('open', $finding->fresh()->status);
    }

    public function test_failed_or_partial_evaluation_never_marks_improvement(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();

        $failedRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'failed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $failedRun,
            evaluationSuccessful: false,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $task = $task->fresh();
        $finding = $finding->fresh();

        $this->assertSame('open', $finding->status);
        $this->assertNotSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->outcome_status);
        $this->assertSame(TaskOutcomeStatus::INSUFFICIENT_EVIDENCE, $task->outcome_status);

        $successRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $successRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinutes(2),
        ));

        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
        $this->assertSame('resolved', $finding->fresh()->status);
    }

    public function test_unrelated_module_or_rule_does_not_change_outcome(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();
        $before = $task->fresh()->outcome_status;
        $checkedAt = $task->fresh()->outcome_checked_at?->toIso8601String();

        $adsRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'google-ads',
            run: $adsRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: ['google-ads:search-term:waste'],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $task = $task->fresh();
        $this->assertSame($before, $task->outcome_status);
        $this->assertSame($checkedAt, $task->outcome_checked_at?->toIso8601String());

        $otherRuleRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $otherRuleRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: ['website:other-rule'],
            matches: [],
            observedAt: now()->addMinutes(2),
        ));

        $task = $task->fresh();
        $this->assertSame($before, $task->outcome_status);
        $this->assertSame('open', $finding->fresh()->status);
    }

    public function test_review_after_timing_is_enforced(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop(
            reviewAfter: now()->addHours(3),
        );

        $tooEarlyRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $tooEarlyRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addHour(),
        ));

        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->fresh()->outcome_status);
        // Finding lifecycle still resolves; Outcome eligibility waits for review-after.
        $this->assertSame('resolved', $finding->fresh()->status);

        $eligibleRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $eligibleRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addHours(4),
        ));

        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
    }

    public function test_incomplete_tasks_are_not_outcome_monitored(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedOpenWebsiteLoop();

        foreach ([TaskStatus::OPEN, TaskStatus::IN_PROGRESS, TaskStatus::BLOCKED] as $status) {
            $task->forceFill(['status' => $status, 'outcome_status' => null])->save();

            $run = Run::factory()->create([
                'digital_asset_id' => $this->websiteAsset->id,
                'module_id' => 'website',
                'status' => 'completed',
            ]);

            app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
                asset: $this->websiteAsset,
                sourceModule: 'website',
                run: $run,
                evaluationSuccessful: true,
                evaluatedRuleIds: [$ruleId],
                matches: [],
                observedAt: now()->addMinute(),
            ));

            $this->assertNull($task->fresh()->outcome_status);
        }
    }

    public function test_legacy_task_without_enhanced_snapshot_can_complete_and_evaluate_via_recommendation(): void
    {
        $ruleId = 'website:legacy:rule';
        $baselineRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'source_module' => 'website',
            'fingerprint' => $ruleId.':item',
            'status' => 'open',
            'severity' => 'high',
            'last_run_id' => $baselineRun->id,
            'last_seen_at' => now()->subDay(),
        ]);

        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->websiteAsset->id,
            'source_module' => 'website',
            'status' => 'open',
        ]);

        $task = Task::factory()->create([
            'recommendation_id' => $recommendation->id,
            'customer_id' => $this->websiteAsset->brand->customer_id,
            'brand_id' => $this->websiteAsset->brand_id,
            'digital_asset_id' => $this->websiteAsset->id,
            'title' => 'Legacy task',
            'action' => 'Do the work',
            'status' => TaskStatus::OPEN,
            'snapshot_json' => [
                'recommendation_id' => $recommendation->id,
                'title' => 'Legacy task',
                'action' => 'Do the work',
            ],
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertOk()
            ->assertSee('Legacy task');

        $task = app(TaskLifecycleService::class)->complete($task, [], $this->admin);
        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->outcome_status);

        $resolveRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $resolveRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
    }

    public function test_task_without_finding_provenance_is_not_evaluable(): void
    {
        $task = Task::factory()->create([
            'recommendation_id' => null,
            'customer_id' => $this->websiteAsset->brand->customer_id,
            'brand_id' => $this->websiteAsset->brand_id,
            'digital_asset_id' => $this->websiteAsset->id,
            'status' => TaskStatus::OPEN,
            'snapshot_json' => [
                'title' => 'Orphan task',
                'action' => 'Unknown provenance',
            ],
        ]);

        $task = app(TaskLifecycleService::class)->complete($task, [], $this->admin);
        $task = app(TaskOutcomeEvaluator::class)->reevaluateFromStoredState($task);

        $this->assertSame(TaskOutcomeStatus::NOT_EVALUABLE, $task->outcome_status);
    }

    public function test_recommendation_mutation_does_not_rewrite_task_snapshot_or_outcome_finding_identity(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();
        $originalSnapshot = $task->snapshot_json;
        $fingerprint = data_get($originalSnapshot, 'finding.fingerprint');

        $recommendation->update([
            'title' => 'Changed recommendation title',
            'action' => 'Changed action',
            'rationale' => 'Changed rationale',
        ]);

        $task = $task->fresh();
        $this->assertSame($originalSnapshot, $task->snapshot_json);
        $this->assertSame($fingerprint, data_get($task->snapshot_json, 'finding.fingerprint'));

        $resolveRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $resolveRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
        $this->assertSame($fingerprint, data_get($task->fresh()->outcome_json, 'source.finding_fingerprint'));
    }

    public function test_google_ads_finding_loop_updates_outcome(): void
    {
        $ruleId = 'google-ads:search-term:waste';
        $baselineRun = Run::factory()->create([
            'digital_asset_id' => $this->adsAsset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->adsAsset->id,
            'source_module' => 'google-ads',
            'fingerprint' => $ruleId.':cheap shoes',
            'status' => 'open',
            'severity' => 'medium',
            'last_run_id' => $baselineRun->id,
            'last_seen_at' => now()->subDay(),
        ]);

        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->adsAsset->id,
            'source_module' => 'google-ads',
            'title' => 'Review negative keywords',
            'action' => 'Review and add approved negative keywords in Google Ads UI.',
            'status' => 'open',
        ]);

        $task = app(CreateTaskFromRecommendation::class)->create($recommendation);
        $task = app(TaskLifecycleService::class)->complete($task, [
            'completion_note' => 'Applied negatives manually in Google Ads.',
        ], $this->admin);

        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->outcome_status);

        $followUp = Run::factory()->create([
            'digital_asset_id' => $this->adsAsset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->adsAsset,
            sourceModule: 'google-ads',
            run: $followUp,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
        $this->assertSame('resolved', $finding->fresh()->status);
    }

    public function test_manual_reevaluate_uses_stored_state_only(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();

        $resolveRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        // Simulate committed Finding state without going through the event listener.
        Event::fake([FindingEvaluationCompleted::class]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $resolveRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->fresh()->outcome_status);
        $this->assertSame('resolved', $finding->fresh()->status);

        $task = app(TaskOutcomeEvaluator::class)->reevaluateFromStoredState($task->fresh());

        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->outcome_status);
        $this->assertSame($resolveRun->id, $task->outcome_run_id);
    }

    public function test_human_lifecycle_actions_and_authorization(): void
    {
        [$finding, $recommendation, $task] = $this->seedOpenWebsiteLoop();
        $lifecycle = app(TaskLifecycleService::class);

        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);

        $task = $lifecycle->start($task, $member);
        $this->assertSame(TaskStatus::IN_PROGRESS, $task->status);

        $task = $lifecycle->block($task, $member);
        $this->assertSame(TaskStatus::BLOCKED, $task->status);

        $task = $lifecycle->resume($task, $member);
        $this->assertSame(TaskStatus::IN_PROGRESS, $task->status);

        $task = $lifecycle->complete($task, [
            'completion_note' => 'Done by team member',
        ], $member);

        $this->assertSame(TaskStatus::COMPLETED, $task->status);
        $this->assertSame($member->id, $task->completed_by_id);
        $this->assertSame('Done by team member', $task->completion_note);
        $this->assertNotNull($task->completed_at);

        $unauthorized = User::factory()->create();
        $openTask = Task::factory()->create([
            'recommendation_id' => $recommendation->id,
            'customer_id' => $this->websiteAsset->brand->customer_id,
            'brand_id' => $this->websiteAsset->brand_id,
            'digital_asset_id' => $this->websiteAsset->id,
            'status' => TaskStatus::OPEN,
        ]);

        $this->expectException(ValidationException::class);
        $lifecycle->start($openTask, $unauthorized);
    }

    public function test_outcome_json_excludes_credentials_and_ai_is_not_invoked_for_classification(): void
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedCompletedWebsiteLoop();

        $resolveRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
            'metadata' => [
                'authorization' => 'Bearer secret-token',
                'api_key' => 'should-not-leak',
            ],
        ]);

        app(FindingLifecycleService::class)->apply(new RuleEvaluationResult(
            asset: $this->websiteAsset,
            sourceModule: 'website',
            run: $resolveRun,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [],
            observedAt: now()->addMinute(),
        ));

        $encoded = json_encode($task->fresh()->outcome_json, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('should-not-leak', $encoded);
        $this->assertStringNotContainsString('Bearer', $encoded);
        $this->assertArrayNotHasKey('prompt', $task->fresh()->outcome_json);
        $this->assertFalse(data_get($task->fresh()->outcome_json, 'causal_attribution'));
    }

    public function test_tasks_workspace_shows_status_and_outcome_separation(): void
    {
        [$finding, $recommendation, $task] = $this->seedCompletedWebsiteLoop();

        Livewire::test(ListTasks::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$task])
            ->assertSee('Completed')
            ->assertSee('Awaiting follow-up');

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertOk()
            ->assertSee('Before')
            ->assertSee('Action')
            ->assertSee('After')
            ->assertSee('Outcome')
            ->assertSee('not causal attribution')
            ->assertActionVisible('reevaluateOutcome');
    }

    public function test_cancel_clears_outcome_monitoring(): void
    {
        [$finding, $recommendation, $task] = $this->seedOpenWebsiteLoop();

        $task = app(TaskLifecycleService::class)->cancel($task, $this->admin);

        $this->assertSame(TaskStatus::CANCELLED, $task->status);
        $this->assertNull($task->outcome_status);
        $this->assertNull($task->outcome_json);
    }

    /**
     * @return array{0: Finding, 1: Recommendation, 2: Task, 3: string}
     */
    private function seedCompletedWebsiteLoop(?\DateTimeInterface $reviewAfter = null): array
    {
        [$finding, $recommendation, $task, $ruleId] = $this->seedOpenWebsiteLoop();

        $task = app(TaskLifecycleService::class)->complete($task, [
            'completion_note' => 'Work completed outside MoxDOP.',
            'outcome_review_after_at' => $reviewAfter,
        ], $this->admin);

        return [$finding, $recommendation, $task, $ruleId];
    }

    /**
     * @return array{0: Finding, 1: Recommendation, 2: Task, 3: string}
     */
    private function seedOpenWebsiteLoop(): array
    {
        $ruleId = 'website:lighthouse:lcp-poor';
        $baselineRun = Run::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->websiteAsset->id,
            'source_module' => 'website',
            'fingerprint' => $ruleId.':'.uniqid('home_', true),
            'status' => 'open',
            'severity' => 'high',
            'title' => 'LCP is poor',
            'last_run_id' => $baselineRun->id,
            'last_seen_at' => now()->subDay(),
        ]);

        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->websiteAsset->id,
            'source_module' => 'website',
            'title' => 'Optimize LCP',
            'action' => 'Compress hero image.',
            'rationale' => 'LCP dominated by hero.',
            'priority' => 'high',
            'status' => 'open',
        ]);

        $task = app(CreateTaskFromRecommendation::class)->create($recommendation, [
            'title' => 'Optimize LCP',
            'priority' => 'high',
        ]);

        return [$finding->fresh(), $recommendation->fresh(), $task, $ruleId];
    }

    private function matchResult(
        DigitalAsset $asset,
        string $sourceModule,
        Run $run,
        string $ruleId,
        string $fingerprint,
        \DateTimeInterface $observedAt,
    ): RuleEvaluationResult {
        return new RuleEvaluationResult(
            asset: $asset,
            sourceModule: $sourceModule,
            run: $run,
            evaluationSuccessful: true,
            evaluatedRuleIds: [$ruleId],
            matches: [
                new RuleMatch(
                    ruleId: $ruleId,
                    fingerprint: $fingerprint,
                    category: 'performance',
                    severity: 'high',
                    title: 'Still present',
                    summary: 'Issue remains.',
                    confidence: 0.8,
                    recommendationTitle: 'Keep working',
                    recommendationAction: 'Continue remediation.',
                ),
            ],
            observedAt: $observedAt,
        );
    }
}
