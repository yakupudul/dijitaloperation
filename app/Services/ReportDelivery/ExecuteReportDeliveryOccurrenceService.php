<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportDeliveryFailureCategory;
use App\Enums\ReportDeliveryOccurrenceStatus;
use App\Enums\ReportDeliveryScheduleStatus;
use App\Models\Brand;
use App\Models\ReportDeliveryOccurrence;
use App\Models\ReportDeliverySchedule;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\ReportSnapshots\CreateReportSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Executes one schedule occurrence: one Snapshot → PDF → per-recipient Deliveries.
 */
final class ExecuteReportDeliveryOccurrenceService
{
    public function __construct(
        private readonly CreateReportSnapshotService $snapshots,
        private readonly GenerateReportPdfService $pdfs,
        private readonly CreateReportDeliveryService $deliveries,
    ) {}

    public function execute(int $occurrenceId): ReportDeliveryOccurrence
    {
        $occurrence = ReportDeliveryOccurrence::query()->find($occurrenceId);
        if ($occurrence === null) {
            throw ValidationException::withMessages(['occurrence' => 'OCCURRENCE_NOT_FOUND']);
        }

        if (in_array($occurrence->status, [
            ReportDeliveryOccurrenceStatus::Completed,
            ReportDeliveryOccurrenceStatus::Cancelled,
        ], true)) {
            return $occurrence;
        }

        $schedule = ReportDeliverySchedule::query()->with('recipients')->find($occurrence->schedule_id);
        if ($schedule === null) {
            return $this->fail($occurrence, ReportDeliveryFailureCategory::AuthorizationInvalidated, 'Schedule missing');
        }

        if ($schedule->status !== ReportDeliveryScheduleStatus::Active
            && $occurrence->report_snapshot_id === null) {
            $occurrence->status = ReportDeliveryOccurrenceStatus::Cancelled;
            $occurrence->save();

            return $occurrence;
        }

        $brand = Brand::query()->find($schedule->brand_id);
        if ($brand === null) {
            return $this->fail($occurrence, ReportDeliveryFailureCategory::AuthorizationInvalidated, 'Brand missing');
        }

        $systemUser = User::query()->find($schedule->created_by) ?? User::query()->orderBy('id')->first();
        if ($systemUser === null) {
            return $this->fail($occurrence, ReportDeliveryFailureCategory::AuthorizationInvalidated, 'No actor');
        }

        try {
            DB::transaction(function () use ($occurrence): void {
                $locked = ReportDeliveryOccurrence::query()->where('id', $occurrence->id)->lockForUpdate()->first();
                if ($locked !== null && $locked->status === ReportDeliveryOccurrenceStatus::Pending) {
                    $locked->status = ReportDeliveryOccurrenceStatus::Claimed;
                    $locked->claimed_at = CarbonImmutable::now();
                    $locked->save();
                }
            });
            $occurrence->refresh();

            if ($occurrence->report_snapshot_id === null) {
                $snapshot = $this->snapshots->create(
                    $brand,
                    $systemUser,
                    [
                        'period_start' => $occurrence->period_start?->toDateString() ?? (string) $occurrence->period_start,
                        'period_end' => $occurrence->period_end?->toDateString() ?? (string) $occurrence->period_end,
                        'locale' => (string) $schedule->locale,
                        'reporting_timezone' => (string) $schedule->timezone,
                        'idempotency_key' => 'occurrence:'.$occurrence->id.':snapshot',
                    ],
                    [(int) $brand->customer_id],
                    [(int) $brand->id],
                );
                $occurrence->report_snapshot_id = (int) $snapshot->id;
                $occurrence->status = ReportDeliveryOccurrenceStatus::SnapshotReady;
                $occurrence->save();
            }

            $snapshot = $occurrence->reportSnapshot()->first()
                ?? ReportSnapshot::query()->find($occurrence->report_snapshot_id);
            if ($snapshot === null) {
                return $this->fail($occurrence, ReportDeliveryFailureCategory::SnapshotGenerationFailed, 'Snapshot missing');
            }

            if ($occurrence->artifact_id === null) {
                $artifact = $this->pdfs->generate(
                    $snapshot,
                    $systemUser,
                    'occurrence:'.$occurrence->id.':pdf',
                    [(int) $brand->customer_id],
                    [(int) $brand->id],
                );
                $occurrence->artifact_id = (int) $artifact->id;
                $occurrence->status = ReportDeliveryOccurrenceStatus::ArtifactReady;
                $occurrence->save();
            }

            $occurrence->status = ReportDeliveryOccurrenceStatus::Distributing;
            $occurrence->save();

            $expiresAt = CarbonImmutable::now()->addHours((int) $schedule->share_ttl_hours);
            foreach ($schedule->recipients->where('enabled', true) as $recipient) {
                $this->deliveries->sendFromSnapshot(
                    $snapshot,
                    [
                        'recipient_email' => (string) $recipient->email,
                        'recipient_name' => $recipient->display_name,
                        'expires_at' => $expiresAt->toIso8601String(),
                        'locale' => (string) ($recipient->locale_override ?: $schedule->locale),
                        'idempotency_key' => 'occurrence:'.$occurrence->id.':recipient:'.strtolower((string) $recipient->email),
                        'schedule_occurrence_id' => (int) $occurrence->id,
                    ],
                    $systemUser,
                    [(int) $brand->customer_id],
                    [(int) $brand->id],
                );
            }

            $occurrence->status = ReportDeliveryOccurrenceStatus::Completed;
            $occurrence->completed_at = CarbonImmutable::now();
            $occurrence->save();

            return $occurrence;
        } catch (\Throwable $e) {
            return $this->fail(
                $occurrence,
                ReportDeliveryFailureCategory::SnapshotGenerationFailed,
                $e->getMessage(),
            );
        }
    }

    private function fail(
        ReportDeliveryOccurrence $occurrence,
        ReportDeliveryFailureCategory $category,
        string $message,
    ): ReportDeliveryOccurrence {
        $occurrence->status = ReportDeliveryOccurrenceStatus::Failed;
        $occurrence->failure_category = $category->value;
        $occurrence->failure_message = mb_substr($message, 0, 500);
        $occurrence->save();

        return $occurrence;
    }
}
