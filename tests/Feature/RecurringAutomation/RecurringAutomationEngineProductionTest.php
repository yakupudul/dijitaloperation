<?php

namespace Tests\Feature\RecurringAutomation;

use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Services\RecurringAutomation\ExecuteRecurringOccurrenceService;
use App\Services\RecurringAutomation\RecurringAutomationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'internal_notification',
            'intelligence_validity_recheck',
        ], $kinds);

        $this->assertTrue(Schema::hasTable('recurring_occurrences'));
        $this->assertTrue(Schema::hasTable('collection_schedules'));
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

    public function test_occurrence_of_a_retired_kind_is_skipped_not_failed(): void
    {
        $occurrence = RecurringOccurrence::query()->create([
            'schedule_kind' => RecurringScheduleKind::BusinessOutcomeRecheck,
            'domain_schedule_id' => 1,
            'scheduled_for' => CarbonImmutable::parse('2026-08-16 09:00:00', 'UTC'),
            'timezone_snapshot' => 'UTC',
            'recurrence_spec_fingerprint' => 'fp',
            'status' => RecurringOccurrenceStatus::Queued,
            'attempt_count' => 0,
            'is_manual' => false,
            'created_at' => now(),
            'occurrence_key' => 'business_outcome_recheck:1:2026-08-16T09:00:00Z',
        ]);

        $result = app(ExecuteRecurringOccurrenceService::class)->execute((int) $occurrence->id);

        $this->assertSame(RecurringOccurrenceStatus::Skipped, $result->status);
        $this->assertSame('KIND_RETIRED', $result->failure_code);
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
