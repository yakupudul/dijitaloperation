<?php

namespace Tests\Feature\ApprovalsQa;

use App\Enums\ApprovalActorKind;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\QaReviewResult;
use App\Enums\QaReviewStatus;
use App\Enums\RecommendationSourceKind;
use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\QaReview;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\Approvals\ApprovalReadService;
use App\Services\Approvals\ApprovalService;
use App\Services\ClientRequests\CreateClientRequest;
use App\Services\Qa\QaReadService;
use App\Services\Qa\QaService;
use App\Services\Tasks\CreateDirectTask;
use App\Services\Tasks\TaskReadService;
use App\Services\Work\WorkReadService;
use App\Support\Roles;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalsQaProductionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Customer $customer;

    private Brand $brand;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Roles::ADMIN);
        $this->actingAs($this->actor);

        $this->customer = Customer::factory()->create(['name' => 'AQ Customer']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'AQ Brand',
        ]);
        $this->task = app(CreateDirectTask::class)->create([
            'title' => 'Ship landing copy',
            'action' => 'Publish approved landing page copy',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
        ], $this->actor, 'aq:task:1');

        Http::fake();
    }

    public function test_canonical_tables_exist_without_works_or_v2(): void
    {
        $this->assertTrue(Schema::hasTable('qa_reviews'));
        $this->assertTrue(Schema::hasTable('approvals'));
        $this->assertFalse(Schema::hasTable('works'));
        $this->assertFalse(Schema::hasTable('approvals_v2'));
        $this->assertFalse(Schema::hasTable('qa_reviews_v2'));
        $this->assertFalse(Schema::hasColumn('tasks', 'approved'));
        $this->assertFalse(Schema::hasColumn('tasks', 'qa_passed'));
    }

    public function test_task_without_qa_is_valid(): void
    {
        $this->assertSame(0, QaReview::query()->where('task_id', $this->task->id)->count());
        $presentation = app(TaskReadService::class)->findPresentation($this->task->id);
        $this->assertNotNull($presentation);
        $this->assertFalse($presentation['qa_required']);
        $this->assertNull($presentation['qa_status']);
        $this->assertNull($presentation['current_qa']);
    }

    public function test_request_qa_creates_one_round_and_is_idempotent(): void
    {
        $qa = app(QaService::class);
        $a = $qa->requestReview($this->task, [], $this->actor, 'qa:req:1');
        $b = $qa->requestReview($this->task, [], $this->actor, 'qa:req:1');
        $c = $qa->requestReview($this->task, [], $this->actor, 'qa:req:active');

        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->id, $c->id);
        $this->assertSame(1, QaReview::query()->where('task_id', $this->task->id)->count());
        $this->assertSame(QaReviewStatus::Pending, $a->status);
        $this->assertNull($a->result);
        $this->assertSame($this->customer->id, $a->customer_id);
        $this->assertSame($this->brand->id, $a->brand_id);
    }

    public function test_complete_pass_and_fail_persist_truthfully(): void
    {
        $qa = app(QaService::class);
        $review = $qa->requestReview($this->task, [], $this->actor, 'qa:pass:1');
        $qa->startReview($review, $this->actor);
        $passed = $qa->completeReview($review, ['result' => QaReviewResult::Passed->value], $this->actor);

        $this->assertSame(QaReviewStatus::Completed, $passed->status);
        $this->assertSame(QaReviewResult::Passed, $passed->result);
        $this->assertSame($this->actor->id, $passed->reviewer_id);
        $this->assertNotNull($passed->completed_at);

        $again = $qa->completeReview($passed, ['result' => QaReviewResult::Failed->value], $this->actor);
        $this->assertSame(QaReviewResult::Passed, $again->result);

        $failedRound = $qa->requestReview($this->task, [], $this->actor, 'qa:fail:1');
        $failed = $qa->completeReview($failedRound, ['result' => QaReviewResult::Failed->value, 'notes' => 'Copy mismatch'], $this->actor);
        $this->assertSame(QaReviewResult::Failed, $failed->result);
        $this->assertSame(2, QaReview::query()->where('task_id', $this->task->id)->count());
    }

    public function test_needs_changes_result_and_re_review_keeps_history(): void
    {
        $qa = app(QaService::class);
        $first = $qa->completeReview(
            $qa->requestReview($this->task, [], $this->actor, 'qa:nc:1'),
            ['result' => QaReviewResult::NeedsChanges->value],
            $this->actor,
        );
        $second = $qa->completeReview(
            $qa->requestReview($this->task, [], $this->actor, 'qa:nc:2'),
            ['result' => QaReviewResult::Passed->value],
            $this->actor,
        );

        $this->assertSame(QaReviewResult::NeedsChanges, $first->fresh()->result);
        $this->assertSame(QaReviewResult::Passed, $second->result);
        $this->assertSame(2, QaReview::query()->where('task_id', $this->task->id)->count());

        $latest = app(QaReadService::class)->latestForTask($this->task);
        $this->assertSame($second->id, $latest['id']);
        $this->assertSame('passed', $latest['result']);
    }

    public function test_task_done_does_not_auto_create_qa_or_approval(): void
    {
        $this->task->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by_id' => $this->actor->id])->save();

        $this->assertSame(0, QaReview::query()->where('task_id', $this->task->id)->count());
        $this->assertSame(0, Approval::query()->where('task_id', $this->task->id)->count());
    }

    public function test_approval_request_decide_reject_changes_requested_and_rounds(): void
    {
        $svc = app(ApprovalService::class);
        $pending = $svc->request($this->task, ['kind' => ApprovalKind::Client->value], $this->actor, 'appr:1');
        $dup = $svc->request($this->task, ['kind' => ApprovalKind::Client->value], $this->actor, 'appr:1');
        $this->assertSame($pending->id, $dup->id);
        $this->assertSame(ApprovalStatus::Pending, $pending->status);
        $this->assertNull($pending->decision);

        $contact = CustomerContact::query()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Client Approver',
            'email' => 'client@example.com',
        ]);

        $decided = $svc->decide($pending, [
            'decision' => ApprovalDecision::ChangesRequested->value,
            'reason' => 'Tone too aggressive',
            'decided_by_actor_kind' => ApprovalActorKind::ClientContact->value,
            'decided_by_customer_contact_id' => $contact->id,
        ], $this->actor);

        $this->assertSame(ApprovalStatus::Decided, $decided->status);
        $this->assertSame(ApprovalDecision::ChangesRequested, $decided->decision);
        $this->assertNotNull($decided->decided_at);

        $retry = $svc->decide($decided, [
            'decision' => ApprovalDecision::Approved->value,
            'decided_by_actor_kind' => ApprovalActorKind::ClientContact->value,
            'decided_by_customer_contact_id' => $contact->id,
        ], $this->actor);
        $this->assertSame(ApprovalDecision::ChangesRequested, $retry->decision);

        $round2 = $svc->request($this->task, ['kind' => ApprovalKind::Client->value], $this->actor, 'appr:2');
        $this->assertNotSame($pending->id, $round2->id);
        $approved = $svc->decide($round2, [
            'decision' => ApprovalDecision::Approved->value,
            'decided_by_actor_kind' => ApprovalActorKind::ClientContact->value,
            'decided_by_customer_contact_id' => $contact->id,
        ], $this->actor);
        $this->assertSame(ApprovalDecision::Approved, $approved->decision);
        $this->assertSame(2, Approval::query()->where('task_id', $this->task->id)->count());
    }

    public function test_qa_pass_does_not_create_approval_and_approval_does_not_create_qa(): void
    {
        $qa = app(QaService::class)->completeReview(
            app(QaService::class)->requestReview($this->task, [], $this->actor, 'sep:qa'),
            ['result' => QaReviewResult::Passed->value],
            $this->actor,
        );
        $this->assertSame(0, Approval::query()->where('task_id', $this->task->id)->count());

        app(ApprovalService::class)->request($this->task, ['kind' => ApprovalKind::Internal->value], $this->actor, 'sep:appr');
        $this->assertSame(1, QaReview::query()->where('task_id', $this->task->id)->count());
        $this->assertSame(QaReviewResult::Passed, $qa->fresh()->result);

        $approved = app(ApprovalService::class)->decide(
            Approval::query()->where('task_id', $this->task->id)->firstOrFail(),
            [
                'decision' => ApprovalDecision::Approved->value,
                'decided_by_actor_kind' => ApprovalActorKind::InternalUser->value,
                'decided_by_user_id' => $this->actor->id,
            ],
            $this->actor,
        );
        $this->assertSame(1, QaReview::query()->where('task_id', $this->task->id)->count());
        $this->assertSame(ApprovalDecision::Approved, $approved->decision);
    }

    public function test_qa_and_approval_do_not_mutate_task_status_finding_or_opportunity(): void
    {
        $finding = Finding::factory()->create([
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ]);
        $opportunity = Opportunity::factory()->create([
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ]);
        $findingStatus = $finding->status ?? $finding->condition_state ?? null;
        $opportunityStatus = $opportunity->status ?? $opportunity->condition_state ?? null;
        $taskStatus = $this->task->status;

        app(QaService::class)->completeReview(
            app(QaService::class)->requestReview($this->task, [], $this->actor, 'bound:qa'),
            ['result' => QaReviewResult::Passed->value],
            $this->actor,
        );
        app(ApprovalService::class)->decide(
            app(ApprovalService::class)->request($this->task, ['kind' => ApprovalKind::Internal->value], $this->actor, 'bound:appr'),
            [
                'decision' => ApprovalDecision::Approved->value,
                'decided_by_actor_kind' => ApprovalActorKind::InternalUser->value,
                'decided_by_user_id' => $this->actor->id,
            ],
            $this->actor,
        );

        $this->assertSame($taskStatus, $this->task->fresh()->status);
        $finding->refresh();
        $opportunity->refresh();
        $this->assertSame($findingStatus, $finding->status ?? $finding->condition_state ?? null);
        $this->assertSame($opportunityStatus, $opportunity->status ?? $opportunity->condition_state ?? null);
    }

    public function test_subject_revision_currentness_excludes_assignee_only_change(): void
    {
        $qa = app(QaService::class)->completeReview(
            app(QaService::class)->requestReview($this->task, [], $this->actor, 'rev:qa'),
            ['result' => QaReviewResult::Passed->value],
            $this->actor,
        );
        $before = $qa->subject_fingerprint;

        $other = User::factory()->create();
        $this->task->forceFill(['assignee_id' => $other->id])->save();
        $this->assertSame($before, TaskReviewedStateFingerprint::for($this->task->fresh()));

        $presentation = app(TaskReadService::class)->findPresentation($this->task->id);
        $this->assertSame('approved', $presentation['qa_status']);
        $this->assertTrue($presentation['current_qa']['is_current_for_subject']);

        $this->task->forceFill(['title' => 'Ship landing copy v2'])->save();
        $presentation = app(TaskReadService::class)->findPresentation($this->task->id);
        $this->assertSame('stale', $presentation['qa_status']);
        $this->assertTrue($presentation['qa_required']);
        $this->assertFalse($presentation['current_qa']['is_current_for_subject']);
        $this->assertSame(QaReviewResult::Passed, $qa->fresh()->result);
    }

    public function test_cross_customer_client_contact_denied(): void
    {
        $otherCustomer = Customer::factory()->create();
        $foreign = CustomerContact::query()->create([
            'customer_id' => $otherCustomer->id,
            'name' => 'Foreign',
            'email' => 'foreign@example.com',
        ]);
        $approval = app(ApprovalService::class)->request(
            $this->task,
            ['kind' => ApprovalKind::Client->value],
            $this->actor,
            'tenancy:1',
        );

        $this->expectException(ValidationException::class);
        app(ApprovalService::class)->decide($approval, [
            'decision' => ApprovalDecision::Approved->value,
            'decided_by_actor_kind' => ApprovalActorKind::ClientContact->value,
            'decided_by_customer_contact_id' => $foreign->id,
        ], $this->actor);
    }

    public function test_unauthorized_actor_denied(): void
    {
        $stranger = User::factory()->create();
        $this->expectException(ValidationException::class);
        app(QaService::class)->requestReview($this->task, [], $stranger, 'auth:qa');
    }

    public function test_activity_recorded_without_note_spam(): void
    {
        app(QaService::class)->completeReview(
            app(QaService::class)->requestReview($this->task, [], $this->actor, 'act:qa'),
            ['result' => QaReviewResult::Passed->value, 'notes' => 'secret qa note'],
            $this->actor,
        );
        app(ApprovalService::class)->decide(
            app(ApprovalService::class)->request($this->task, ['kind' => ApprovalKind::Internal->value], $this->actor, 'act:appr'),
            [
                'decision' => ApprovalDecision::Approved->value,
                'notes' => 'secret approval note',
                'decided_by_actor_kind' => ApprovalActorKind::InternalUser->value,
                'decided_by_user_id' => $this->actor->id,
            ],
            $this->actor,
        );

        $events = BrandContextActivity::query()
            ->where('brand_id', $this->brand->id)
            ->pluck('event')
            ->all();
        $this->assertContains('QA_REQUESTED', $events);
        $this->assertContains('QA_COMPLETED', $events);
        $this->assertContains('APPROVAL_REQUESTED', $events);
        $this->assertContains('APPROVAL_APPROVED', $events);

        $payloads = BrandContextActivity::query()->where('brand_id', $this->brand->id)->pluck('payload');
        foreach ($payloads as $payload) {
            $this->assertArrayNotHasKey('notes', is_array($payload) ? $payload : []);
        }
    }

    public function test_work_aggregate_projects_qa_and_approval_without_demo_approvals(): void
    {
        app(QaService::class)->requestReview($this->task, [], $this->actor, 'work:qa');
        app(ApprovalService::class)->request($this->task, ['kind' => ApprovalKind::Client->value], $this->actor, 'work:appr');

        $items = collect(app(WorkReadService::class)->workItems());
        $taskRow = $items->first(fn (array $row): bool => ($row['type'] ?? '') === 'task' && (string) $row['id'] === (string) $this->task->id);
        $approvalRows = $items->where('type', 'approval');

        $this->assertNotNull($taskRow);
        $this->assertTrue($taskRow['qa_required']);
        $this->assertSame('ready', $taskRow['qa_status']);
        $this->assertTrue($approvalRows->contains(fn (array $row): bool => (int) ($row['task_id'] ?? 0) === $this->task->id));
        $this->assertFalse($items->contains(fn (array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'appr-')));
    }

    public function test_recommendation_accepted_is_not_approval(): void
    {
        $recommendation = Recommendation::factory()->create([
            'source_kind' => RecommendationSourceKind::Finding->value,
            'status' => 'accepted',
        ]);
        $this->assertSame(0, Approval::query()->count());
        $this->assertSame('accepted', $recommendation->fresh()->status);
        $this->assertSame(TaskSourceKind::Direct, $this->task->source_kind);
    }

    public function test_client_request_accepted_is_not_approval(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Please update ads',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ], $this->actor);

        $this->assertSame(0, Approval::query()->where('task_id', $this->task->id)->count());
        $this->assertNotNull($request->id);
    }

    public function test_provider_boundary_zero_http(): void
    {
        app(QaService::class)->completeReview(
            app(QaService::class)->requestReview($this->task, [], $this->actor, 'http:qa'),
            ['result' => QaReviewResult::Passed->value],
            $this->actor,
        );
        app(ApprovalService::class)->decide(
            app(ApprovalService::class)->request($this->task, ['kind' => ApprovalKind::Internal->value], $this->actor, 'http:appr'),
            [
                'decision' => ApprovalDecision::Rejected->value,
                'reason' => 'No',
                'decided_by_actor_kind' => ApprovalActorKind::InternalUser->value,
                'decided_by_user_id' => $this->actor->id,
            ],
            $this->actor,
        );
        app(TaskReadService::class)->findPresentation($this->task->id);
        app(ApprovalReadService::class)->forWorkItemPresentation();
        app(WorkReadService::class)->workItems();

        Http::assertNothingSent();
    }
}
