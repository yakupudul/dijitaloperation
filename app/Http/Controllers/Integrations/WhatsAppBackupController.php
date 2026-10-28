<?php

namespace App\Http\Controllers\Integrations;

use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppBackupLine;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\Backup\WhatsAppBackupSpreadsheet;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Upload of the phone's WhatsApp Business backup in pieces (large files pass the server's request limits); the
 * extraction itself starts from the WhatsApp screen's "Çıkar" with the 64-digit key. The last extracted file and the
 * saved key of each backup (?line=, none for the inbox's own) can be downloaded again by an admin.
 */
final class WhatsAppBackupController
{
    public function begin(Request $request, WhatsAppBackupImporter $importer): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1'], 'line' => ['nullable', 'integer'], 'excel' => ['nullable', 'boolean']]);
        $import = $importer->begin($request->user(), $data['name'], (int) $data['size'], isset($data['line']) ? (int) $data['line'] : null, (bool) ($data['excel'] ?? false));

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

        return response()->json(['received' => $row->received_bytes, 'complete' => $row->status !== 'uploading', 'extracting' => in_array($row->status, ['queued', 'running'], true)]);
    }

    /** "Dosyayı indir": the last extracted backup file, exactly as it was uploaded (still encrypted). */
    public function file(Request $request, WhatsAppConnection $connection, WhatsAppBackupImporter $importer): BinaryFileResponse
    {
        $connection->authorize($request->user());
        $line = $this->line($request) ?? WhatsAppBackupLine::shown();
        $current = $importer->current($line?->id);
        abort_unless($current['file_kept'], 404);
        $date = $current['import']->finished_at?->timezone('Europe/Istanbul')->format('Y-m-d');
        $name = ($line !== null ? Str::slug($line->name).'-' : '').($date !== null ? $date.'-' : '').'msgstore.db.crypt15';

        return response()->download(WhatsAppBackupImport::keptPath($current['import']->line_id), $name, ['Content-Type' => 'application/octet-stream']);
    }

    /** "Anahtarı indir": the saved 64-digit key as a text file (never logged, never cached). */
    public function key(Request $request, WhatsAppConnection $connection, WhatsAppBackupImporter $importer): Response
    {
        $connection->authorize($request->user());
        $line = $this->line($request);
        $key = $importer->savedKey($line?->id);
        abort_if($key === null, 404);
        $name = ($line !== null ? Str::slug($line->name).'-' : '').'whatsapp-yedek-anahtari.txt';

        return response(implode(' ', str_split($key, 4)).PHP_EOL, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** "Excel indir" of one uploaded backup: only the chats inside that file. */
    public function excel(Request $request, string $import, WhatsAppConnection $connection): BinaryFileResponse
    {
        $connection->authorize($request->user());
        $row = WhatsAppBackupImport::query()->where('status', 'completed')->findOrFail($import);
        $path = WhatsAppBackupSpreadsheet::open($row);
        abort_if($path === null, 404);
        $name = Str::slug((string) preg_replace('/\.crypt\d+$/i', '', $row->file_name)) ?: 'yedek';
        $file = 'whatsapp-'.($row->line?->name ? Str::slug($row->line->name).'-' : '').$name.'-'.($row->finished_at?->timezone('Europe/Istanbul')->format('Y-m-d-Hi') ?? 'excel').'.xlsx';

        return response()->download($path, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private'])
            ->deleteFileAfterSend();
    }

    private function line(Request $request): ?WhatsAppBackupLine
    {
        $id = $request->integer('line') ?: null;

        return $id === null ? null : WhatsAppBackupLine::query()->findOrFail($id);
    }
}
