<?php

namespace App\Services\Prospects;

use App\Enums\ProspectReportProjection;
use App\Enums\ProspectSalesIntelligenceStatus;
use App\Models\Prospect;
use App\Models\ProspectReportSnapshot;
use App\Models\ProspectSalesIntelligence;
use App\Models\User;
use App\Support\ReportSnapshots\ReportSnapshotChecksum;
use Illuminate\Validation\ValidationException;

final class CreateProspectReportSnapshotService
{
    public function __construct(
        private readonly ProspectReportProjectionService $projections = new ProspectReportProjectionService,
        private readonly ProspectActivityRecorder $activities = new ProspectActivityRecorder,
    ) {}

    public function generate(
        Prospect $prospect,
        ProspectReportProjection $projection,
        ?User $actor = null,
        ?string $locale = null,
        ?string $idempotencyKey = null,
        ?string $internalNotes = null,
    ): ProspectReportSnapshot {
        $prospect->load(['latestResearchRun', 'latestSalesIntelligence', 'evidence', 'owner']);

        if ($projection === ProspectReportProjection::ClientShareable) {
            $intelligence = $prospect->latestSalesIntelligence;
            $hasEvidence = $prospect->evidence->isNotEmpty();
            $hasIntelligence = $intelligence instanceof ProspectSalesIntelligence
                && $intelligence->status === ProspectSalesIntelligenceStatus::Available;

            if (! $hasEvidence && ! $hasIntelligence) {
                throw ValidationException::withMessages([
                    'report' => [__('operator.prospects.reports.unavailable')],
                ]);
            }
        }

        $locale = in_array($locale, ['en', 'tr'], true) ? $locale : (in_array(app()->getLocale(), ['en', 'tr'], true) ? app()->getLocale() : 'en');

        $payload = $projection === ProspectReportProjection::ClientShareable
            ? $this->projections->clientShareable($prospect)
            : $this->projections->internal($prospect);

        if ($projection === ProspectReportProjection::Internal && is_string($internalNotes) && trim($internalNotes) !== '') {
            $payload['internal_notes'] = trim($internalNotes);
        }

        if ($projection === ProspectReportProjection::ClientShareable) {
            $this->projections->assertClientSafe($payload);
        }

        $checksum = ReportSnapshotChecksum::hash($payload);
        $key = $idempotencyKey ?: ('prospect-report:'.$prospect->id.':'.$projection->value.':'.$checksum);

        $existing = ProspectReportSnapshot::query()
            ->where('prospect_id', $prospect->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing instanceof ProspectReportSnapshot) {
            return $existing;
        }

        $title = $projection === ProspectReportProjection::ClientShareable
            ? __('operator.prospects.reports.client_title', ['name' => $prospect->company_name], $locale)
            : __('operator.prospects.reports.internal_title', ['name' => $prospect->company_name], $locale);

        $snapshot = ProspectReportSnapshot::query()->create([
            'prospect_id' => $prospect->id,
            'prospect_research_run_id' => $prospect->latestResearchRun?->id,
            'prospect_sales_intelligence_id' => $prospect->latestSalesIntelligence?->id,
            'projection' => $projection,
            'locale' => $locale,
            'title' => $title,
            'content_payload' => $payload,
            'content_checksum' => $checksum,
            'idempotency_key' => $key,
            'generated_by' => $actor?->id,
            'generated_at' => now(),
            'created_at' => now(),
        ]);

        $this->activities->record(
            $prospect,
            'prospect.report_generated',
            __('operator.prospects.activity.report_generated'),
            $projection->value,
            $actor,
            ['snapshot_id' => $snapshot->id, 'projection' => $projection->value],
        );

        return $snapshot;
    }
}
