<?php

namespace App\Services\ReportDelivery;

use App\Models\ReportSnapshot;
use App\Support\ReportDelivery\ReportPdfRendererVersion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;

/**
 * Snapshot-only PDF renderer (Prompt 60).
 * Never queries live Findings / Outcomes / ClientValueStoryReadService.
 */
final class ReportPdfRenderer
{
    /**
     * @return array{bytes: string, renderer_version: string, html: string}
     */
    public function render(ReportSnapshot $snapshot): array
    {
        $content = $snapshot->content_payload;
        if (! is_array($content)) {
            throw ValidationException::withMessages(['content_payload' => 'INVALID_SNAPSHOT_PAYLOAD']);
        }

        $story = is_array($content['story'] ?? null) ? $content['story'] : [];
        $outcomes = is_array($story['business_outcomes'] ?? null) ? $story['business_outcomes'] : [];
        $locale = (string) ($snapshot->locale ?: 'en');
        $rendererVersion = ReportPdfRendererVersion::current();

        $observations = array_values(array_map(
            static fn (array $row): string => (string) ($row['text'] ?? ''),
            array_filter($story['observations'] ?? [], 'is_array'),
        ));
        $opportunities = array_values(array_map(
            static fn (array $row): string => (string) ($row['title'] ?? ''),
            array_filter($story['opportunities'] ?? [], 'is_array'),
        ));
        $completedWork = array_values(array_map(
            static fn (array $row): string => (string) ($row['text'] ?? ''),
            array_filter($story['completed_work'] ?? [], 'is_array'),
        ));

        $html = view('reports.client-value-story-pdf', [
            'title' => (string) $snapshot->title_snapshot,
            'brandName' => (string) $snapshot->brand_name_snapshot,
            'customerName' => (string) $snapshot->customer_name_snapshot,
            'periodStart' => $snapshot->period_start?->toDateString(),
            'periodEnd' => $snapshot->period_end?->toDateString(),
            'generatedAt' => $snapshot->generated_at?->toIso8601String(),
            'locale' => $locale,
            'observations' => $observations,
            'opportunities' => $opportunities,
            'completedWork' => $completedWork,
            'outcomesAvailable' => (bool) ($outcomes['available'] ?? false),
            'outcomesUnavailable' => $outcomes['unavailable_message'] ?? null,
            'qualifiedLeads' => $outcomes['qualified_leads'] ?? '—',
            'consultations' => $outcomes['consultations'] ?? '—',
            'patients' => $outcomes['patients'] ?? '—',
            'revenueDisplay' => $outcomes['revenue_display'] ?? ($outcomes['revenue'] ?? '—'),
            'causationDisclaimer' => $story['causation_disclaimer'] ?? '',
            'limitations' => array_values(array_map('strval', $content['limitations'] ?? [])),
            'rendererVersion' => $rendererVersion,
            'schemaVersion' => $snapshot->snapshot_schema_version?->value
                ?? (string) $snapshot->snapshot_schema_version,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');
        $bytes = $pdf->output();

        if ($bytes === '' || ! str_starts_with($bytes, '%PDF')) {
            throw ValidationException::withMessages(['pdf' => 'PDF_GENERATION_FAILED']);
        }

        return [
            'bytes' => $bytes,
            'renderer_version' => $rendererVersion,
            'html' => $html,
        ];
    }
}
