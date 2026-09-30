<?php

namespace Tests\Feature\ActivityNotifications;

use App\Enums\DomainEventType;
use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\Customer;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
