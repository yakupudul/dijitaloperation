<?php

namespace App\Services\Prospects;

use App\Enums\ProspectReportProjection;
use App\Models\ProspectReportArtifact;
use App\Models\ProspectReportSnapshot;
use App\Support\ReportSnapshots\ReportSnapshotChecksum;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ProspectReportPdfRenderer
{
    public const string RENDERER_VERSION = 'prospect_pre_analysis_pdf_v1';

    /**
     * @return array{bytes: string, renderer_version: string, html: string}
     */
    public function render(ProspectReportSnapshot $snapshot): array
    {
        $content = is_array($snapshot->content_payload) ? $snapshot->content_payload : [];
        $view = $snapshot->projection === ProspectReportProjection::ClientShareable
            ? 'reports.prospect-client-pre-analysis-pdf'
            : 'reports.prospect-internal-pre-analysis-pdf';

        $html = view($view, [
            'snapshot' => $snapshot,
            'content' => $content,
            'locale' => $snapshot->locale,
            'rendererVersion' => self::RENDERER_VERSION,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');
        $bytes = $pdf->output();

        if ($bytes === '' || ! str_starts_with($bytes, '%PDF')) {
            throw ValidationException::withMessages(['pdf' => 'PDF_GENERATION_FAILED']);
        }

        return [
            'bytes' => $bytes,
            'renderer_version' => self::RENDERER_VERSION,
            'html' => $html,
        ];
    }

    public function generateArtifact(ProspectReportSnapshot $snapshot): ProspectReportArtifact
    {
        $existing = ProspectReportArtifact::query()
            ->where('prospect_report_snapshot_id', $snapshot->id)
            ->where('renderer_version', self::RENDERER_VERSION)
            ->first();

        if ($existing instanceof ProspectReportArtifact) {
            return $existing;
        }

        $rendered = $this->render($snapshot);
        $checksum = ReportSnapshotChecksum::hash(['bytes' => hash('sha256', $rendered['bytes'])]);
        $directory = 'prospect-reports/'.$snapshot->id.'/'.self::RENDERER_VERSION;
        $filename = $checksum.'.pdf';
        $path = $directory.'/'.$filename;

        Storage::disk('local')->put($path, $rendered['bytes']);

        return ProspectReportArtifact::query()->create([
            'prospect_report_snapshot_id' => $snapshot->id,
            'artifact_type' => 'pdf',
            'renderer_version' => self::RENDERER_VERSION,
            'disk' => 'local',
            'path' => $path,
            'checksum' => hash('sha256', $rendered['bytes']),
            'byte_size' => strlen($rendered['bytes']),
            'created_at' => now(),
        ]);
    }
}
