<?php

namespace App\Http\Controllers\Prospects;

use App\Models\ProspectReportArtifact;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProspectReportArtifactDownloadController
{
    public function download(string $prospectId, string $artifactId): StreamedResponse
    {
        abort_unless(ctype_digit($prospectId) && ctype_digit($artifactId), 404);

        $artifact = ProspectReportArtifact::query()
            ->with('snapshot')
            ->findOrFail($artifactId);

        abort_unless((string) $artifact->snapshot?->prospect_id === $prospectId, 404);

        $disk = Storage::disk($artifact->disk);
        abort_unless($disk->exists($artifact->path), 404);

        return $disk->download($artifact->path, 'prospect-pre-analysis.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
