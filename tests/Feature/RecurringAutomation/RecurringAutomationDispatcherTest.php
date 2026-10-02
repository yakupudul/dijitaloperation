<?php

namespace Tests\Feature\RecurringAutomation;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Jobs\RecurringAutomation\ExecuteRecurringOccurrenceJob;
use App\Models\RecurringOccurrence;
use App\Services\RecurringAutomation\ExecuteRecurringOccurrenceService;
use App\Services\RecurringAutomation\RecurringAutomationDispatcher;
use App\Services\RecurringAutomation\RecurringAutomationRegistry;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecurringAutomationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_throws_for_unknown_kind(): void
    {
        $registry = new RecurringAutomationRegistry([]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->adapter(RecurringScheduleKind::Collection);
    }

    public function test_dispatcher_ensures_occurrence_and_queues_job(): void
    {
        Queue::fake();

        $scheduledFor = CarbonImmutable::parse('2026-08-16 09:00:00', 'UTC');
        $spec = new RecurringScheduleSpec(
            timezone: 'UTC',
            frequency: RecurringFrequency::Daily,
            interval: 1,
            localTime: '09:00',
        );

        $adapter = new class($spec, $scheduledFor) implements RecurringScheduleAdapter
        {
            public function __construct(
                private RecurringScheduleSpec $spec,
                private CarbonImmutable $scheduledFor,
            ) {}

            public function kind(): RecurringScheduleKind
            {
                return RecurringScheduleKind::InternalNotification;
            }

            public function discoverDue(?CarbonImmutable $nowUtc = null): array
            {
                return [[
                    'domain_schedule_id' => 42,
                    'spec' => $this->spec,
                    'scheduled_for_utc' => $this->scheduledFor,
                ]];
            }

            public function execute(RecurringOccurrence $occurrence): RecurringScheduleAdapterResult
            {
                return new RecurringScheduleAdapterResult(
                    status: RecurringOccurrenceStatus::Completed,
                    domainRunType: RecurringDomainRunType::NotificationBatch,
                    domainRunId: 7,
                );
            }

            public function isScheduleActive(int $domainScheduleId): bool
            {
                return true;
            }

            public function allowedFrequencies(): array
            {
                return [RecurringFrequency::Daily];
            }

            public function defaultMisfirePolicy(): RecurringMisfirePolicy
            {
                return RecurringMisfirePolicy::SkipMissed;
            }

            public function supportsManualRun(): bool
            {
                return true;
            }
        };

        $registry = new RecurringAutomationRegistry([$adapter]);
        $this->app->instance(RecurringAutomationRegistry::class, $registry);

        $ids = (new RecurringAutomationDispatcher($registry))->dispatchDue();

        $this->assertCount(1, $ids);
        $occurrence = RecurringOccurrence::query()->findOrFail($ids[0]);
        $this->assertSame(RecurringOccurrenceStatus::Queued, $occurrence->status);
        $this->assertSame(42, (int) $occurrence->domain_schedule_id);

        Queue::assertPushed(ExecuteRecurringOccurrenceJob::class, function (ExecuteRecurringOccurrenceJob $job) use ($ids): bool {
            return $job->occurrenceId === $ids[0];
        });

        $executor = new ExecuteRecurringOccurrenceService($registry);
        $finished = $executor->execute((int) $occurrence->id);

        $this->assertSame(RecurringOccurrenceStatus::Completed, $finished->status);
        $this->assertSame(RecurringDomainRunType::NotificationBatch, $finished->domain_run_type);
        $this->assertSame(7, (int) $finished->domain_run_id);
        $this->assertNotNull($finished->finished_at);
    }

    public function test_dispatch_due_automations_command_runs(): void
    {
        $this->artisan('moxdop:dispatch-due-automations')
            ->expectsOutput('Dispatched occurrences: 0')
            ->assertSuccessful();
    }
}
