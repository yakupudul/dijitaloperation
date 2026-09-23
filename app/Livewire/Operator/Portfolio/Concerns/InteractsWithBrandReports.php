<?php

namespace App\Livewire\Operator\Portfolio\Concerns;

use App\Models\Brand;
use App\Models\ReportDeliverySchedule;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\ReportDelivery\CreateReportDeliveryService;
use App\Services\ReportDelivery\GenerateReportPdfService;
use App\Services\ReportDelivery\ReportDeliveryScheduleService;
use App\Services\ReportDelivery\ReportMailConfigGuard;
use App\Services\ReportSnapshots\CreateReportSnapshotService;
use App\Services\ReportSnapshots\ReportSnapshotReadService;
use App\Support\Demo\ClientValueFixtures;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brand reports: snapshot, PDF, e-mail delivery and monthly schedule (all persisted through the
 * report services). Needs $brand (numeric id string) and the demo period trait on the component.
 */
trait InteractsWithBrandReports
{
    public string $reportLanguage = 'en';

    public string $reportTone = 'client';

    public string $reportOperatorNote = '';

    /** @var array<string, bool> */
    public array $reportSections = [];

    #[Url(as: 'snapshot')]
    public string $snapshotId = '';

    public string $snapshotTitle = '';

    public string $snapshotCreateNonce = '';

    public string $snapshotStatusMessage = '';

    public string $snapshotStatusTone = 'info';

    public string $deliveryRecipientEmail = '';

    public string $deliveryRecipientName = '';

    public string $deliveryNonce = '';

    public string $deliveryStatusMessage = '';

    public string $deliveryStatusTone = 'info';

    public string $scheduleRecipientEmail = '';

    public int $scheduleDayOfMonth = 5;

    public string $scheduleDeliveryTime = '09:00';

    public string $scheduleTimezone = 'Europe/Istanbul';

    public string $scheduleStatusMessage = '';

    public string $scheduleStatusTone = 'info';

    public function mountBrandReports(): void
    {
        $this->hydrateReportComposer();
        $this->snapshotCreateNonce = (string) Str::uuid();
        $this->deliveryNonce = (string) Str::uuid();
    }

    public function createReportSnapshot(): void
    {
        if (! ctype_digit($this->brand)) {
            $this->snapshotStatusTone = 'info';
            $this->snapshotStatusMessage = __('operator.reports.snapshot_requires_production_brand');

            return;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            $this->snapshotStatusTone = 'info';
            $this->snapshotStatusMessage = __('operator.reports.snapshot_auth_required');

            return;
        }

        $period = (string) ($this->period ?: 'last_28');
        $periodBounds = DemoPeriod::bounds($period, $this->periodStart, $this->periodEnd);
        $start = ($this->periodStart && $this->periodEnd) ? $this->periodStart : $periodBounds['start']->toDateString();
        $end = ($this->periodStart && $this->periodEnd) ? $this->periodEnd : $periodBounds['end']->toDateString();

        try {
            $brand = Brand::query()->findOrFail((int) $this->brand);
            $snapshot = app(CreateReportSnapshotService::class)->create(
                $brand,
                $actor,
                [
                    'period_start' => $start,
                    'period_end' => $end,
                    'locale' => $this->reportLanguage,
                    'title' => $this->snapshotTitle !== '' ? $this->snapshotTitle : null,
                    'idempotency_key' => 'ui:'.$this->snapshotCreateNonce,
                ],
                [(int) $brand->customer_id],
                [(int) $brand->id],
            );
            $this->snapshotId = (string) $snapshot->id;
            $this->snapshotCreateNonce = (string) Str::uuid();
            $this->snapshotStatusTone = 'success';
            $this->snapshotStatusMessage = __('operator.reports.snapshot_created');
            DemoState::flash(__('operator.reports.snapshot_created'));
        } catch (ValidationException $e) {
            $this->snapshotStatusTone = 'error';
            $this->snapshotStatusMessage = collect($e->errors())->flatten()->first()
                ?? __('operator.reports.snapshot_create_failed');
        } catch (\Throwable) {
            $this->snapshotStatusTone = 'error';
            $this->snapshotStatusMessage = __('operator.reports.snapshot_create_failed');
        }
    }

    public function clearReportSnapshotView(): void
    {
        $this->snapshotId = '';
        $this->deliveryStatusMessage = '';
        $this->scheduleStatusMessage = '';
    }

    public function generateReportPdf(): void
    {
        if (! ctype_digit($this->brand) || ! ctype_digit($this->snapshotId)) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = __('operator.reports.snapshot_not_found');

            return;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = __('operator.reports.snapshot_auth_required');

            return;
        }

        try {
            $brand = Brand::query()->findOrFail((int) $this->brand);
            $snapshot = ReportSnapshot::query()->findOrFail((int) $this->snapshotId);
            app(GenerateReportPdfService::class)->generate(
                $snapshot,
                $actor,
                'ui:pdf:'.$this->snapshotId.':'.$this->deliveryNonce,
                [(int) $brand->customer_id],
                [(int) $brand->id],
            );
            $this->deliveryStatusTone = 'success';
            $this->deliveryStatusMessage = __('operator.reports.pdf_generated');
        } catch (ValidationException $e) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = collect($e->errors())->flatten()->first()
                ?? __('operator.reports.pdf_generate_failed');
        } catch (\Throwable) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = __('operator.reports.pdf_generate_failed');
        }
    }

    public function sendReportDelivery(): void
    {
        if (! ctype_digit($this->brand) || ! ctype_digit($this->snapshotId)) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = __('operator.reports.snapshot_not_found');

            return;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = __('operator.reports.snapshot_auth_required');

            return;
        }

        if (! app(ReportMailConfigGuard::class)->isConfigured()) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = __('operator.reports.mail_not_configured');

            return;
        }

        try {
            $brand = Brand::query()->findOrFail((int) $this->brand);
            $snapshot = ReportSnapshot::query()->findOrFail((int) $this->snapshotId);
            app(CreateReportDeliveryService::class)->sendFromSnapshot(
                $snapshot,
                [
                    'recipient_email' => $this->deliveryRecipientEmail,
                    'recipient_name' => $this->deliveryRecipientName !== '' ? $this->deliveryRecipientName : null,
                    'locale' => $this->reportLanguage,
                    'idempotency_key' => 'ui:send:'.$this->snapshotId.':'.$this->deliveryNonce,
                ],
                $actor,
                [(int) $brand->customer_id],
                [(int) $brand->id],
            );
            $this->deliveryNonce = (string) Str::uuid();
            $this->deliveryStatusTone = 'success';
            $this->deliveryStatusMessage = __('operator.reports.delivery_queued');
        } catch (ValidationException $e) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = collect($e->errors())->flatten()->first()
                ?? __('operator.reports.delivery_failed');
        } catch (\Throwable) {
            $this->deliveryStatusTone = 'error';
            $this->deliveryStatusMessage = __('operator.reports.delivery_failed');
        }
    }

    public function createReportDeliverySchedule(): void
    {
        if (! ctype_digit($this->brand)) {
            $this->scheduleStatusTone = 'error';
            $this->scheduleStatusMessage = __('operator.reports.snapshot_requires_production_brand');

            return;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            $this->scheduleStatusTone = 'error';
            $this->scheduleStatusMessage = __('operator.reports.snapshot_auth_required');

            return;
        }

        try {
            $brand = Brand::query()->findOrFail((int) $this->brand);
            $schedule = app(ReportDeliveryScheduleService::class)->create(
                $brand,
                [
                    'locale' => $this->reportLanguage,
                    'timezone' => $this->scheduleTimezone,
                    'day_of_month' => $this->scheduleDayOfMonth,
                    'delivery_time' => $this->scheduleDeliveryTime,
                    'recipients' => [
                        ['email' => $this->scheduleRecipientEmail],
                    ],
                ],
                $actor,
                [(int) $brand->customer_id],
                [(int) $brand->id],
            );
            $preview = app(ReportDeliveryScheduleService::class)
                ->previewNextOccurrence($schedule);
            $this->scheduleStatusTone = 'success';
            $this->scheduleStatusMessage = __('operator.reports.schedule_created', [
                'next' => $preview['scheduled_for'],
                'period' => $preview['period_start'].' → '.$preview['period_end'],
            ]);
        } catch (ValidationException $e) {
            $this->scheduleStatusTone = 'error';
            $this->scheduleStatusMessage = collect($e->errors())->flatten()->first()
                ?? __('operator.reports.schedule_failed');
        } catch (\Throwable) {
            $this->scheduleStatusTone = 'error';
            $this->scheduleStatusMessage = __('operator.reports.schedule_failed');
        }
    }

    public function hydrateReportComposer(): void
    {
        $saved = DemoState::reportConfig();
        $this->reportLanguage = (string) ($saved['language'] ?? 'en');
        $this->reportTone = (string) ($saved['tone'] ?? 'client');
        $this->reportOperatorNote = (string) ($saved['operator_note'] ?? '');
        $defaults = array_fill_keys(ClientValueFixtures::reportSectionKeys(), true);
        $sections = is_array($saved['sections'] ?? null) ? $saved['sections'] : [];
        $this->reportSections = array_merge($defaults, array_map('boolval', $sections));
    }

    public function toggleReportSection(string $key): void
    {
        if (! array_key_exists($key, $this->reportSections)) {
            return;
        }
        $this->reportSections[$key] = ! $this->reportSections[$key];
        $this->persistReportComposer();
    }

    public function setReportLanguage(string $language): void
    {
        if (in_array($language, ['en', 'tr'], true)) {
            $this->reportLanguage = $language;
            $this->persistReportComposer();
        }
    }

    public function setReportTone(string $tone): void
    {
        if (in_array($tone, ['client', 'internal'], true)) {
            $this->reportTone = $tone;
            $this->persistReportComposer();
        }
    }

    public function refreshReportPreview(): void
    {
        $this->persistReportComposer();
    }

    private function persistReportComposer(): void
    {
        DemoState::setReportConfig([
            'period' => $this->period,
            'language' => $this->reportLanguage,
            'tone' => $this->reportTone,
            'operator_note' => $this->reportOperatorNote,
            'sections' => $this->reportSections,
        ]);
    }

    /**
     * Snapshot history, schedules and the opened snapshot for the reports tab.
     *
     * @return array{reportSnapshots: array<string, mixed>, reportSnapshotDetail: ?array<string, mixed>}
     */
    protected function brandReportData(Brand $brand): array
    {
        $history = app(ReportSnapshotReadService::class)->listForBrand($brand, ['per_page' => 20], [(int) $brand->customer_id], [(int) $brand->id]);
        $reportSnapshots = [
            'items' => collect($history->items())->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title_snapshot,
                'period_start' => $row->period_start?->toDateString(),
                'period_end' => $row->period_end?->toDateString(),
                'generated_at' => $row->generated_at?->toIso8601String(),
                'brand_name' => (string) $row->brand_name_snapshot,
                'locale' => (string) $row->locale,
            ])->all(),
            'empty' => $history->total() === 0,
            'demo' => false,
            'schedules' => ReportDeliverySchedule::query()->where('brand_id', (int) $brand->id)->orderByDesc('id')->limit(10)->get()
                ->map(static fn ($schedule): array => [
                    'id' => (int) $schedule->id,
                    'status' => $schedule->status?->value ?? (string) $schedule->status,
                    'day_of_month' => (int) $schedule->day_of_month,
                    'delivery_time' => (string) $schedule->delivery_time,
                    'timezone' => (string) $schedule->timezone,
                    'recipients' => $schedule->recipients()->where('enabled', true)->pluck('email')->all(),
                ])->all(),
        ];
        $detail = null;
        if ($this->snapshotId !== '' && ctype_digit($this->snapshotId)) {
            try {
                $detail = app(ReportSnapshotReadService::class)->detail((int) $this->snapshotId, [(int) $brand->customer_id], [(int) $brand->id]);
                if (is_array($detail) && isset($detail['delivery']['artifact_id'])) {
                    $artifactId = $detail['delivery']['artifact_id'];
                    $detail['pdf_download_url'] = $artifactId ? route('reports.artifacts.download', ['artifactId' => $artifactId]) : null;
                }
            } catch (\Throwable) {
                $detail = null;
                $this->snapshotStatusTone = 'error';
                $this->snapshotStatusMessage = __('operator.reports.snapshot_not_found');
            }
        }

        return ['reportSnapshots' => $reportSnapshots, 'reportSnapshotDetail' => $detail];
    }
}
