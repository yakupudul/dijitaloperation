<?php

namespace App\Http\Controllers\Integrations;

use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Upload of the phone's WhatsApp Business backup in pieces (large files pass the server's request limits); the
 * extraction itself starts from the WhatsApp screen's "Çıkar" with the 64-digit key.
 */
final class WhatsAppBackupController
{
    public function begin(Request $request, WhatsAppBackupImporter $importer): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1']]);
        $import = $importer->begin($request->user(), $data['name'], (int) $data['size']);

        return response()->json(['id' => $import->id, 'chunk' => WhatsAppBackupImporter::CHUNK_BYTES]);
    }

    public function chunk(Request $request, string $import, WhatsAppBackupImporter $importer): JsonResponse
    {
        $offset = $request->query('offset');
        abort_unless(is_string($offset) && ctype_digit($offset), 422, 'Dosya parçası geçersiz. Dosyayı yeniden seçin.');
        try {
            $row = $importer->chunk($request->user(), $import, (int) $offset, $request->getContent());
        } catch (HttpException $exception) {
            if (str_starts_with($exception->getMessage(), 'expected_offset:')) {
                return response()->json(['message' => 'Yükleme kaldığı yerden sürüyor.', 'expected_offset' => (int) substr($exception->getMessage(), 16)], 409);
            }
            throw $exception;
        }

        return response()->json(['received' => $row->received_bytes, 'complete' => $row->status === 'uploaded']);
    }
}
