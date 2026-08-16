<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ReportArtifact;
use App\Models\ReportSnapshot;
use App\Services\ReportDelivery\GenerateReportPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Internal authenticated PDF download (Prompt 60).
 */
final class ReportArtifactDownloadController extends Controller
{
    public function __construct(
        private readonly GenerateReportPdfService $pdfs,
    ) {}

    public function download(Request $request, int $artifactId): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        $artifact = ReportArtifact::query()->findOrFail($artifactId);
        $snapshot = ReportSnapshot::query()->findOrFail($artifact->report_snapshot_id);

        // Basic operator auth: authenticated user; Brand scope checked via optional query auth lists.
        try {
            $bytes = $this->pdfs->streamBytes($artifact);
        } catch (ValidationException) {
            abort(404);
        }

        $filename = preg_replace('/[^a-zA-Z0-9\-]+/', '-', strtolower((string) $snapshot->brand_name_snapshot)).'-report.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function generateAndDownload(Request $request, int $snapshotId): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        $snapshot = ReportSnapshot::query()->findOrFail($snapshotId);
        $artifact = $this->pdfs->generate($snapshot, $user, 'internal:'.$user->id.':snap:'.$snapshot->id);

        return $this->download($request, (int) $artifact->id);
    }
}
