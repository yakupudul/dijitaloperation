<?php

namespace App\Http\Controllers\Prospects;

use App\Services\Prospects\ProspectReportPdfRenderer;
use App\Services\Prospects\ProspectReportShareService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class ProspectReportShareController
{
    public function locator(string $token): View
    {
        $grant = app(ProspectReportShareService::class)->resolveActiveGrant($token);
        $snapshot = $grant->snapshot;
        $content = is_array($snapshot?->content_payload) ? $snapshot->content_payload : [];

        return view('reports.prospect-client-share', [
            'snapshot' => $snapshot,
            'content' => $content,
            'token' => $token,
        ]);
    }

    public function pdf(string $token): Response
    {
        $grant = app(ProspectReportShareService::class)->resolveActiveGrant($token);
        $snapshot = $grant->snapshot;
        abort_unless($snapshot !== null && $snapshot->isClientShareable(), 404);

        $artifact = app(ProspectReportPdfRenderer::class)->generateArtifact($snapshot);
        $bytes = Storage::disk($artifact->disk)->get($artifact->path);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="pre-analysis.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
