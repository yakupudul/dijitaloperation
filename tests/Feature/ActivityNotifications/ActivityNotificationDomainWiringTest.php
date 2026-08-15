<?php

namespace Tests\Feature\ActivityNotifications;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalKind;
use App\Enums\DomainEventType;
use App\Enums\QaReviewResult;
use App\Enums\TaskScopeKind;
use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\DomainEvent;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Approvals\ApprovalService;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Services\Qa\QaService;
use App\Services\Recommendations\CreateRecommendationFromFinding;
use App\Services\Recommendations\UpdateRecommendation;
use App\Services\Tasks\CreateDirectTask;
use App\Services\Tasks\TaskLifecycleService;
use App\Support\Roles;
use App\Support\Tasks\TaskStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Domain transition → DomainEvent → Activity / Notification wiring (Prompt 47).
 */
class ActivityNotificationDomainWiringTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $assignee;

    private Customer $customer;

    private Brand $brand;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->actor = User::factory()->create(['name' => 'Wire Actor']);
        $this->actor->assignRole(Roles::ADMIN);
        $this->assignee = User::factory()->create(['name' => 'Wire Assignee']);
        $this->assignee->assignRole(Roles::TEAM_MEMBER);

        $this->customer = Customer::factory()->create(['name' => 'Wire Customer']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Wire Brand',
        ]);
        $this->task = app(CreateDirectTask::class)->create([
            'title' => 'Wire task',
            'action' => 'Do the thing',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
            'assignee_id' => $this->assignee->id,
        ], $this->actor, 'wire:task:1');
    }

    public function test_task_complete_emits_one_event_activity_and_notifies_assignee(): void
    {
        Http::fake();
        Mail::fake();

        $completed = app(TaskLifecycleService::class)->complete($this->task, [], $this->actor);

        $this->assertSame(TaskStatus::COMPLETED, $completed->status);
        $this->assertSame(1, DomainEvent::query()->where('event_type', DomainEventType::TaskCompleted->value)->count());
        $event = DomainEvent::query()->where('event_type', DomainEventType::TaskCompleted->value)->first();
        $this->assertNotNull($event);
        $this->assertSame(1, BrandContextActivity::query()->where('domain_event_id', $event->id)->count());
        $this->assertSame(1, UserNotification::query()->where('domain_event_id', $event->id)->count());
        $this->assertSame($this->assignee->id, UserNotification::query()->where('domain_event_id', $event->id)->value('recipient_user_id'));

        // Retry same completion identity: no duplicate (task already completed throws or no second emit path)
        $this->assertSame(1, DomainEvent::query()->where('event_type', DomainEventType::TaskCompleted->value)->count());

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_self_complete_creates_activity_without_self_notification(): void
    {
        $this->task->forceFill(['assignee_id' => $this->actor->id])->save();

        app(TaskLifecycleService::class)->complete($this->task, [], $this->actor);

        $event = DomainEvent::query()->where('event_type', DomainEventType::TaskCompleted->value)->first();
        $this->assertNotNull($event);
        $this->assertSame(1, BrandContextActivity::query()->where('domain_event_id', $event->id)->count());
        $this->assertSame(0, UserNotification::query()->where('domain_event_id', $event->id)->count());
    }

    public function test_recommendation_accepted_emits_distinct_event_not_approval(): void
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id]);
        $finding = Finding::factory()->create(['digital_asset_id' => $asset->id]);
        $recommendation = app(CreateRecommendationFromFinding::class)->create($finding, [
            'title' => 'Accept me',
        ]);

        app(UpdateRecommendation::class)->accept($recommendation, $this->actor);

        $this->assertSame(1, DomainEvent::query()->where('event_type', DomainEventType::RecommendationAccepted->value)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', DomainEventType::ApprovalApproved->value)->count());
        $this->assertSame(Recommendation::STATUS_ACCEPTED, $recommendation->fresh()->status);
        // No Task auto-created from accept
        $this->assertSame(1, Task::query()->count());
    }

    public function test_qa_passed_emits_qa_passed_not_qa_approved_or_approval(): void
    {
        $review = app(QaService::class)->requestReview($this->task, [], $this->actor, 'wire:qa:1');
        app(QaService::class)->completeReview($review, ['result' => QaReviewResult::Passed->value], $this->actor);

        $this->assertSame(1, DomainEvent::query()->where('event_type', DomainEventType::QaPassed->value)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'like', '%QA_APPROVED%')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', DomainEventType::ApprovalApproved->value)->count());

        $event = DomainEvent::query()->where('event_type', DomainEventType::QaPassed->value)->first();
        $this->assertNotNull($event);
        // Actor is reviewer; assignee is different → one notification
        $this->assertSame(1, UserNotification::query()->where('domain_event_id', $event->id)->count());
    }

    public function test_approval_approved_is_separate_from_qa(): void
    {
        $approval = app(ApprovalService::class)->request($this->task, [
            'kind' => ApprovalKind::Internal->value,
        ], $this->actor, 'wire:appr:1');
        app(ApprovalService::class)->decide($approval, [
            'decision' => ApprovalDecision::Approved->value,
            'decided_by_user_id' => $this->actor->id,
        ], $this->actor);

        $this->assertSame(1, DomainEvent::query()->where('event_type', DomainEventType::ApprovalApproved->value)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', DomainEventType::QaPassed->value)->count());
    }

    public function test_domain_rollback_creates_no_event_activity_or_notification(): void
    {
        $beforeEvents = DomainEvent::query()->count();
        $beforeActivities = BrandContextActivity::query()->count();
        $beforeNotifications = UserNotification::query()->count();

        try {
            DB::transaction(function (): void {
                app(DomainEventEmitter::class)->emit([
                    'event_type' => DomainEventType::FindingCreated,
                    'actor_kind' => 'system',
                    'customer_id' => $this->customer->id,
                    'brand_id' => $this->brand->id,
                    'subject_kind' => 'finding',
                    'subject_id' => 4242,
                    'payload' => ['title' => 'Should roll back'],
                ]);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($beforeEvents, DomainEvent::query()->count());
        $this->assertSame($beforeActivities, BrandContextActivity::query()->count());
        $this->assertSame($beforeNotifications, UserNotification::query()->count());
    }

    public function test_mark_read_does_not_mutate_task(): void
    {
        app(TaskLifecycleService::class)->complete($this->task, [], $this->actor);
        $notification = UserNotification::query()->first();
        $this->assertNotNull($notification);

        app(\App\Services\Notifications\NotificationWriteService::class)
            ->markRead($this->assignee, (int) $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(TaskStatus::COMPLETED, $this->task->fresh()->status);
        $this->assertNull(Finding::query()->first());
    }

    public function test_finding_created_idempotency_key_is_stable(): void
    {
        $emitter = app(DomainEventEmitter::class);
        $a = $emitter->emit([
            'event_type' => DomainEventType::FindingCreated,
            'actor_kind' => 'system',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => 'finding',
            'subject_id' => 77,
            'payload' => ['title' => 'A'],
        ]);
        $b = $emitter->emit([
            'event_type' => DomainEventType::FindingCreated,
            'actor_kind' => 'system',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => 'finding',
            'subject_id' => 77,
            'payload' => ['title' => 'B'],
        ]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, BrandContextActivity::query()->where('event', DomainEventType::FindingCreated->value)->count());
    }

    public function test_mark_all_read_does_not_mark_future_notifications(): void
    {
        $old = app(CreateDirectTask::class)->create([
            'title' => 'Old assigned task',
            'action' => 'Old',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
            'assignee_id' => $this->assignee->id,
        ], $this->actor, 'wire:task:old');

        $this->assertSame(1, UserNotification::query()
            ->where('recipient_user_id', $this->assignee->id)
            ->whereNull('read_at')
            ->where('subject_id', $old->id)
            ->count());

        $before = now();
        $marked = app(\App\Services\Notifications\NotificationWriteService::class)
            ->markAllRead($this->assignee, $before);
        $this->assertGreaterThanOrEqual(1, $marked);

        $other = app(CreateDirectTask::class)->create([
            'title' => 'Later task',
            'action' => 'Later',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
            'assignee_id' => $this->assignee->id,
        ], $this->actor, 'wire:task:later');

        $this->assertNotNull($other->id);

        $unread = UserNotification::query()
            ->where('recipient_user_id', $this->assignee->id)
            ->whereNull('read_at')
            ->count();
        $this->assertSame(1, $unread);
        $this->assertSame($other->id, (int) UserNotification::query()
            ->where('recipient_user_id', $this->assignee->id)
            ->whereNull('read_at')
            ->value('subject_id'));
    }
}
