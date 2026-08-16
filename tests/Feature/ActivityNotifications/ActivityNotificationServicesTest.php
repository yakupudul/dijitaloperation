<?php

namespace Tests\Feature\ActivityNotifications;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\TaskScopeKind;
use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\Customer;
use App\Models\DomainEvent;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Activity\ActivityReadService;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationReadService;
use App\Services\Notifications\NotificationUiActions;
use App\Services\Notifications\NotificationWriteService;
use App\Services\Tasks\CreateTask;
use App\Support\Notifications\NotificationPreferenceCatalog;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityNotificationServicesTest extends TestCase
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
        $this->actor = User::factory()->create(['name' => 'Actor']);
        $this->actor->assignRole(Roles::ADMIN);
        $this->assignee = User::factory()->create(['name' => 'Assignee']);
        $this->assignee->assignRole(Roles::TEAM_MEMBER);

        $this->customer = Customer::factory()->create(['name' => 'AN Customer']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'AN Brand',
        ]);
        // Create without assignee so setUp does not emit TASK_ASSIGNED; tests control assignment events.
        $this->task = app(CreateTask::class)->create([
            'title' => 'Fix landing CTA',
            'action' => 'Update CTA copy',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
            'assignee_id' => null,
            'source_kind' => 'direct',
        ], $this->actor, 'an:task:1');
        $this->task->forceFill(['assignee_id' => $this->assignee->id])->save();
    }

    public function test_production_tables_and_preference_catalog_exist(): void
    {
        $this->assertTrue(Schema::hasTable('domain_events'));
        $this->assertTrue(Schema::hasTable('user_notifications'));
        $this->assertTrue(Schema::hasTable('notification_preferences'));
        $this->assertTrue(Schema::hasColumn('brand_context_activities', 'domain_event_id'));
        $this->assertTrue(Schema::hasColumn('brand_context_activities', 'occurred_at'));

        $keys = NotificationPreferenceCatalog::keys();
        $this->assertContains('critical_finding', $keys);
        $this->assertContains('operation_failed', $keys);
        $this->assertContains('scheduled_internal_notification', $keys);
        $this->assertContains('business_outcome_recheck', $keys);
        $this->assertCount(14, $keys);
    }

    public function test_emitter_is_idempotent_and_projects_activity_and_notification(): void
    {
        $emitter = app(DomainEventEmitter::class);

        $first = $emitter->emit([
            'event_type' => DomainEventType::TaskCompleted,
            'actor_kind' => DomainEventActorKind::InternalUser,
            'actor_user_id' => $this->actor->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => DomainEventSubjectKind::Task,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Fix landing CTA', 'status' => 'done'],
        ]);

        $second = $emitter->emit([
            'event_type' => DomainEventType::TaskCompleted,
            'actor_kind' => DomainEventActorKind::InternalUser,
            'actor_user_id' => $this->actor->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => DomainEventSubjectKind::Task,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Fix landing CTA', 'status' => 'done'],
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('TASK_COMPLETED:task:'.$this->task->id, $first->idempotency_key);
        $this->assertSame('projected', $first->projection_status);

        $this->assertSame(1, BrandContextActivity::query()->where('domain_event_id', $first->id)->count());
        $this->assertSame(1, UserNotification::query()->where('domain_event_id', $first->id)->count());

        $notification = UserNotification::query()->where('domain_event_id', $first->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame($this->assignee->id, $notification->recipient_user_id);
        $this->assertSame($this->assignee->id, $notification->recipient_user_id);
        $this->assertArrayHasKey('title_key', $notification->presentation ?? []);
    }

    public function test_emitter_skips_activity_when_brand_id_null_but_still_projects(): void
    {
        $event = app(DomainEventEmitter::class)->emit([
            'event_type' => DomainEventType::FindingCreated,
            'actor_kind' => DomainEventActorKind::System,
            'customer_id' => $this->customer->id,
            'brand_id' => null,
            'subject_kind' => DomainEventSubjectKind::Finding,
            'subject_id' => 99,
            'payload' => ['title' => 'Customer-scoped finding'],
        ]);

        $this->assertSame('FINDING_CREATED:finding:99', $event->idempotency_key);
        $this->assertSame('projected', $event->projection_status);
        $this->assertSame(0, BrandContextActivity::query()->where('domain_event_id', $event->id)->count());
        $this->assertSame(0, UserNotification::query()->where('domain_event_id', $event->id)->count());
    }

    public function test_self_suppression_when_actor_is_assignee(): void
    {
        $this->task->forceFill(['assignee_id' => $this->actor->id])->save();

        $event = app(DomainEventEmitter::class)->emit([
            'event_type' => DomainEventType::TaskCompleted,
            'actor_kind' => DomainEventActorKind::InternalUser,
            'actor_user_id' => $this->actor->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => DomainEventSubjectKind::Task,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Self complete'],
        ]);

        $this->assertSame(0, UserNotification::query()->where('domain_event_id', $event->id)->count());
        $this->assertSame(1, BrandContextActivity::query()->where('domain_event_id', $event->id)->count());
    }

    public function test_preference_default_true_and_disable_blocks_notification(): void
    {
        $prefs = app(NotificationPreferenceService::class);
        $this->assertTrue($prefs->isInAppEnabled($this->assignee, 'task_assigned'));

        $prefs->setPreference($this->assignee, 'task_assigned', false, false);
        $this->assertFalse($prefs->isInAppEnabled($this->assignee, 'task_assigned'));

        $event = app(DomainEventEmitter::class)->emit([
            'event_type' => DomainEventType::TaskAssigned,
            'actor_kind' => DomainEventActorKind::InternalUser,
            'actor_user_id' => $this->actor->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => DomainEventSubjectKind::Task,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Assigned', 'assignee_id' => $this->assignee->id],
        ]);

        $this->assertSame(0, UserNotification::query()->where('domain_event_id', $event->id)->count());
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->assignee->id,
            'preference_key' => 'task_assigned',
            'in_app_enabled' => 0,
            'email_enabled' => 0,
        ]);
    }

    public function test_notification_read_write_and_ui_actions(): void
    {
        $event = app(DomainEventEmitter::class)->emit([
            'event_type' => DomainEventType::TaskAssigned,
            'actor_kind' => DomainEventActorKind::InternalUser,
            'actor_user_id' => $this->actor->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => DomainEventSubjectKind::Task,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Assigned work'],
        ]);

        $reads = app(NotificationReadService::class);
        $this->assertSame(1, $reads->unreadCount($this->assignee));
        $list = $reads->forUser($this->assignee, unreadOnly: true);
        $this->assertCount(1, $list);

        $id = (int) $list[0]['id'];
        $ui = app(NotificationUiActions::class);
        $this->assertTrue($ui->markRead($this->assignee, $id)['ok']);
        $this->assertSame(0, $reads->unreadCount($this->assignee));

        // Idempotent re-read
        $this->assertTrue(app(NotificationWriteService::class)->markRead($this->assignee, $id)?->read_at !== null);

        $second = app(DomainEventEmitter::class)->emit([
            'event_type' => DomainEventType::TaskCompleted,
            'actor_kind' => DomainEventActorKind::InternalUser,
            'actor_user_id' => $this->actor->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => DomainEventSubjectKind::Task,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Done'],
        ]);
        $this->assertNotSame($event->id, $second->id);
        $this->assertSame(1, $reads->unreadCount($this->assignee));

        $marked = $ui->markAllRead($this->assignee);
        $this->assertTrue($marked['ok']);
        $this->assertSame(0, $reads->unreadCount($this->assignee));
    }

    public function test_activity_read_includes_projected_and_legacy_rows(): void
    {
        app(DomainEventEmitter::class)->emit([
            'event_type' => DomainEventType::TaskCompleted,
            'actor_kind' => DomainEventActorKind::InternalUser,
            'actor_user_id' => $this->actor->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'subject_kind' => DomainEventSubjectKind::Task,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Fix landing CTA'],
        ]);

        BrandContextActivity::query()->create([
            'brand_id' => $this->brand->id,
            'customer_id' => $this->customer->id,
            'actor_user_id' => $this->actor->id,
            'actor_kind' => 'internal_user',
            'event' => 'LEGACY_EVENT',
            'subject_type' => Task::class,
            'subject_id' => $this->task->id,
            'payload' => ['title' => 'Legacy row'],
            'occurred_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
        ]);

        $rows = app(ActivityReadService::class)->forList([
            'brand_id' => $this->brand->id,
            'limit' => 20,
        ]);

        $events = collect($rows)->pluck('event')->all();
        $this->assertContains(DomainEventType::TaskCompleted->value, $events);
        $this->assertContains('LEGACY_EVENT', $events);
        $this->assertArrayHasKey('event_label', $rows[0]);
        $this->assertArrayHasKey('relative', $rows[0]);
    }

    public function test_emit_works_inside_existing_db_transaction(): void
    {
        DB::transaction(function (): void {
            $event = app(DomainEventEmitter::class)->emit([
                'event_type' => DomainEventType::ClientRequestCreated,
                'actor_kind' => DomainEventActorKind::InternalUser,
                'actor_user_id' => $this->actor->id,
                'customer_id' => $this->customer->id,
                'brand_id' => $this->brand->id,
                'subject_kind' => DomainEventSubjectKind::ClientRequest,
                'subject_id' => 7,
                'payload' => ['title' => 'Need new ads'],
            ], 'CLIENT_REQUEST_CREATED:client_request:7');

            $this->assertSame('projected', $event->projection_status);
        });

        $this->assertSame(1, DomainEvent::query()->where('idempotency_key', 'CLIENT_REQUEST_CREATED:client_request:7')->count());
    }

    public function test_preference_list_matches_frozen_catalog(): void
    {
        $list = app(NotificationPreferenceService::class)->listForUser($this->actor);
        $this->assertCount(14, $list);
        $this->assertSame(NotificationPreferenceCatalog::keys(), array_column($list, 'preference_key'));
        $this->assertTrue($list[0]['in_app_enabled']);
        $this->assertFalse($list[0]['email_enabled']);
        $this->assertSame(0, NotificationPreference::query()->count());
    }
}
