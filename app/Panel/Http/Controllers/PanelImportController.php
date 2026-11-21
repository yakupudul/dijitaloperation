<?php

namespace App\Panel\Http\Controllers;

use App\Panel\Models\PanelImport;
use App\Panel\Services\PanelBackups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The pieces of a WhatsApp backup a brand's manager uploads (the file is too large for one request). */
final class PanelImportController
{
    public function chunk(Request $request, string $import, PanelBackups $backups): JsonResponse
    {
        abort_unless(strlen($import) <= 18 && ctype_digit($import), 404);
        $row = PanelImport::query()->findOrFail((int) $import);
        $offset = (int) $request->query('offset', '0');
        $saved = $backups->chunk($row, $offset, (string) $request->getContent());
        if ($saved->received_bytes !== $offset + strlen((string) $request->getContent())) {
            return response()->json(['expected_offset' => $saved->received_bytes], 409);
        }

        return response()->json(['received' => $saved->received_bytes, 'done' => $saved->status === 'uploaded']);
    }
}
