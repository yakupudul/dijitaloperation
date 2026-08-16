<?php

namespace Tests\Feature\RecurringAutomation;

use App\Enums\BusinessOutcomeRecheckResultStatus;
use App\Enums\CollectionScheduleStatus;
use App\Enums\InternalNotificationScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Enums\ReportDeliveryScheduleStatus;
use App\Jobs\RecurringAutomation\ExecuteRecurringOccurrenceJob;
use App\Models\Brand;
use App\Models\BusinessOutcomeRecheckRun;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\RecurringOccurrence;
use App\Models\ReportDeliverySchedule;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\BusinessOutcomes\BusinessOutcomeDefinitionService;
use App\Services\BusinessOutcomes\BusinessOutcomeRecheckScheduleService;
use App\Services\Collection\CollectionScheduleService;
use App\Services\Notifications\InternalNotificationScheduleService;
use App\Services\RecurringAutomation\ExecuteRecurringOccurrenceService;
use App\Services\RecurringAutomation\RecurringAutomationDispatcher;
use App\Services\RecurringAutomation\RecurringAutomationRegistry;
use App\Services\ReportDelivery\ReportDeliveryScheduleService;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecurringAutomationEngineProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_and_architecture_boundaries(): void
    {
        $registry = app(RecurringAutomationRegistry::class);
        $kinds = array_map(static fn ($k) => $k->value, $registry->kinds());
        $this->assertSame([
            'collection',
            'recurring_review',
            'business_outcome_recheck',
            'internal_notification',
            'report_delivery',
            'intelligence_validity_recheck',
        ], $kinds);

        $this->assertTrue(Schema::hasTable('recurring_occurrences'));
        $this->assertTrue(Schema::hasTable('collection_schedules'));
        $this->assertTrue(Schema::hasTable('business_outcome_recheck_schedules'));
        $this->assertTrue(Schema::hasTable('internal_notification_schedules'));
        $this->assertFalse(Schema::hasTable('automation_steps'));
        $this->assertFalse(Schema::hasTable('workflow_nodes'));
        $this->assertFalse(class_exists('App\\Models\\SchedulerV2'));
        $this->assertFalse(class_exists('App\\Models\\ReviewScheduleV2'));
        $this->assertFalse(class_exists('App\\Models\\ReportDeliveryScheduleV2'));
        $this->assertFalse(class_exists('App\\Models\\GenericAutomation'));
        $this->assertFalse(class_exists('App\\Services\\IntelligenceScheduling\\IntelligenceEngineV2'));
        $this->assertContains(RecurringScheduleKind::IntelligenceValidityRecheck->value, $kinds);
    }

    public function test_double_dispatcher_creates_one_occurrence(): void
    {
        Queue::fake();
        [$user, $brand] = $this->seedBrand();
        $schedule = app(InternalNotificationScheduleService::class)->create([
            'brand_id' => (int) $brand->id,
            'customer_id' => (int) $brand->customer_id,
            'timezone' => 'UTC',
            'frequency' => 'daily',
            'local_time' => '09:00',
            'title' => 'Daily ops check',
            'message' => 'Review the Brand dashboard.',
            'safe_route_name' => 'demo.activity',
            'recipient_user_ids' => [(int) $user->id],
        ], $user);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 09:05:00', 'UTC'));
        $dispatcher = app(RecurringAutomationDispatcher::class);
        $a = $dispatcher->dispatchDue(CarbonImmutable::now('UTC'), [RecurringScheduleKind::InternalNotification]);
        $b = $dispatcher->dispatchDue(CarbonImmutable::now('UTC'), [RecurringScheduleKind::InternalNotification]);
        CarbonImmutable::setTestNow();

        $this->assertCount(1, $a);
        $this->assertSame([], $b);
        $this->assertSame(1, RecurringOccurrence::query()
            ->where('schedule_kind', RecurringScheduleKind::InternalNotification)
            ->where('domain_schedule_id', $schedule->id)
            ->count());
        Queue::assertPushed(ExecuteRecurringOccurrenceJob::class, 1);
    }

    public function test_internal_notification_idempotent_and_no_notify_all(): void
    {
        [$user, $brand] = $this->seedBrand();
        $other = User::factory()->create();
        $schedule = app(InternalNotificationScheduleService::class)->create([
            'brand_id' => (int) $brand->id,
            'customer_id' => (int) $brand->customer_id,
            'timezone' => 'UTC',
            'frequency' => 'daily',
            'local_time' => '09:00',
            'title' => 'Reminder',
            'message' => 'Plain text only',
            'recipient_user_ids' => [(int) $user->id],
        ], $user);

        $occurrence = RecurringOccurrence::query()->create([
            'schedule_kind' => RecurringScheduleKind::InternalNotification,
            'domain_schedule_id' => (int) $schedule->id,
            'scheduled_for' => CarbonImmutable::parse('2026-08-16 09:00:00', 'UTC'),
            'timezone_snapshot' => 'UTC',
            'recurrence_spec_fingerprint' => 'fp',
            'status' => RecurringOccurrenceStatus::Queued,
            'attempt_count' => 0,
            'is_manual' => false,
            'created_at' => now(),
            'occurrence_key' => 'internal_notification:'.$schedule->id.':2026-08-16T09:00:00Z',
        ]);

        $exec = app(ExecuteRecurringOccurrenceService::class);
        $first = $exec->execute((int) $occurrence->id);
        $second = $exec->execute((int) $occurrence->id);

        $this->assertSame(RecurringOccurrenceStatus::Completed, $first->status);
        $this->assertSame(RecurringOccurrenceStatus::Completed, $second->status);
        $this->assertSame(1, UserNotification::query()->where('recipient_user_id', $user->id)->count());
        $this->assertSame(0, UserNotification::query()->where('recipient_user_id', $other->id)->count());
    }

    public function test_outcome_recheck_no_data_without_provider_or_writes(): void
    {
        [$user, $brand] = $this->seedBrand();
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);

        $schedule = app(BusinessOutcomeRecheckScheduleService::class)->create($brand, [
            'timezone' => 'UTC',
            'frequency' => 'monthly',
            'day_of_month' => 5,
            'delivery_time' => '09:00',
            'period_strategy' => 'previous_calendar_month',
            'attention_on_no_data' => true,
            'recipient_user_ids' => [(int) $user->id],
        ], $user);

        $occurrence = RecurringOccurrence::query()->create([
            'schedule_kind' => RecurringScheduleKind::BusinessOutcomeRecheck,
            'domain_schedule_id' => (int) $schedule->id,
            'scheduled_for' => CarbonImmutable::parse('2026-08-05 09:00:00', 'UTC'),
            'timezone_snapshot' => 'UTC',
            'recurrence_spec_fingerprint' => 'fp',
            'status' => RecurringOccurrenceStatus::Queued,
            'attempt_count' => 0,
            'is_manual' => false,
            'created_at' => now(),
            'occurrence_key' => 'business_outcome_recheck:'.$schedule->id.':2026-08-05T09:00:00Z',
        ]);

        $beforeObs = (int) DB::table('business_outcome_observations')->count();
        $result = app(ExecuteRecurringOccurrenceService::class)->execute((int) $occurrence->id);
        $this->assertSame(RecurringOccurrenceStatus::Completed, $result->status);

        $run = BusinessOutcomeRecheckRun::query()->where('recurring_occurrence_id', $occurrence->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('2026-07-01', $run->period_start?->toDateString());
        $this->assertSame('2026-07-31', $run->period_end?->toDateString());
        $statuses = collect($run->results_payload)->pluck('status')->all();
        $this->assertContains(BusinessOutcomeRecheckResultStatus::NoData->value, $statuses);
        foreach ($run->results_payload as $row) {
            if (($row['status'] ?? '') === BusinessOutcomeRecheckResultStatus::NoData->value) {
                $this->assertNull($row['value']);
            }
        }
        $this->assertSame($beforeObs, (int) DB::table('business_outcome_observations')->count());
        $this->assertSame(0, DB::table('tasks')->count());
        $this->assertSame(0, DB::table('findings')->count());
        $this->assertTrue((bool) $run->notified);
        $this->assertGreaterThanOrEqual(1, UserNotification::query()->where('recipient_user_id', $user->id)->count());
    }

    public function test_collection_schedule_create_and_pause(): void
    {
        [$user, $brand] = $this->seedBrand();
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);
        $schedule = app(CollectionScheduleService::class)->create($asset, [
            'frequency' => 'daily',
            'timezone' => 'Europe/Istanbul',
            'local_time' => '06:00',
        ], $user, [(int) $brand->customer_id], [(int) $brand->id]);

        $this->assertSame(CollectionScheduleStatus::Active, $schedule->status);
        $this->assertSame(RecurringFrequency::Daily, $schedule->frequency);
        app(CollectionScheduleService::class)->pause($schedule);
        $this->assertSame(CollectionScheduleStatus::Paused, $schedule->fresh()->status);
    }

    public function test_report_delivery_adapter_registered_and_command_converges(): void
    {
        Queue::fake();
        [$user, $brand] = $this->seedBrand();
        app(ReportDeliveryScheduleService::class)->create($brand, [
            'timezone' => 'UTC',
            'day_of_month' => 5,
            'delivery_time' => '09:00',
            'recipients' => [['email' => 'a@client.com']],
        ], $user);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 09:10:00', 'UTC'));
        $this->artisan('reports:dispatch-due-deliveries')->assertSuccessful();
        CarbonImmutable::setTestNow();

        $this->assertSame(1, RecurringOccurrence::query()
            ->where('schedule_kind', RecurringScheduleKind::ReportDelivery)
            ->count());
        $this->assertSame(1, ReportDeliverySchedule::query()
            ->where('status', ReportDeliveryScheduleStatus::Active)
            ->count());
        Queue::assertPushed(ExecuteRecurringOccurrenceJob::class, 1);
    }

    public function test_misfire_catch_up_bounded_and_skip_missed(): void
    {
        $calc = app(RecurringOccurrenceCalculator::class);
        $spec = new RecurringScheduleSpec(
            timezone: 'UTC',
            frequency: RecurringFrequency::Daily,
            interval: 1,
            localTime: '09:00',
            misfirePolicy: RecurringMisfirePolicy::CatchUpBounded,
        );
        $a = $calc->nextOccurrence($spec, CarbonImmutable::parse('2026-08-10 08:00:00', 'UTC'));
        $this->assertSame('2026-08-10 09:00:00', $a->format('Y-m-d H:i:s'));

        [$user, $brand] = $this->seedBrand();
        $paused = app(InternalNotificationScheduleService::class)->create([
            'timezone' => 'UTC',
            'frequency' => 'daily',
            'local_time' => '09:00',
            'title' => 'X',
            'message' => 'Y',
            'recipient_user_ids' => [(int) $user->id],
        ], $user);
        app(InternalNotificationScheduleService::class)->pause($paused);
        $this->assertSame(InternalNotificationScheduleStatus::Paused, $paused->fresh()->status);

        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 09:05:00', 'UTC'));
        $ids = app(RecurringAutomationDispatcher::class)->dispatchDue(
            CarbonImmutable::now('UTC'),
            [RecurringScheduleKind::InternalNotification],
        );
        CarbonImmutable::setTestNow();
        $this->assertSame([], $ids);
    }

    public function test_no_arbitrary_execution_primitives(): void
    {
        $this->assertFalse(class_exists('App\\Services\\RecurringAutomation\\RunShellAction'));
        $this->assertFalse(class_exists('App\\Services\\RecurringAutomation\\RunSqlAction'));
        $this->assertFalse(class_exists('App\\Services\\RecurringAutomation\\RunHttpAction'));
        $this->assertFalse(enum_exists('App\\Enums\\RecurringScheduleKind') && RecurringScheduleKind::tryFrom('ai_prompt') !== null);
        $this->assertNull(RecurringScheduleKind::tryFrom('command'));
        $this->assertNull(RecurringScheduleKind::tryFrom('script'));
    }

    /**
     * @return array{0: User, 1: Brand}
     */
    private function seedBrand(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Atlas Dental']);

        return [$user, $brand];
    }
}
