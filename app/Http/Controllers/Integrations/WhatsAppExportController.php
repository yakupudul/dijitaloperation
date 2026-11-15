<?php

namespace App\Http\Controllers\Integrations;

use App\Jobs\WhatsApp\BuildWhatsAppExport;
use App\Models\WhatsAppBackupLine;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Excel'e aktar" on the WhatsApp screens: every conversation (or the open one with ?conversation=) as an .xlsx; with
 * ?line= the chats of that backup row only, wherever they sit. A small export downloads at once; a big one (a whole
 * phone backup) is written by a heavy-queue job while a waiting page polls and then starts the download, because
 * writing 394k messages in the request ran past PHP's 30 s limit (500, 2026-11-15).
 */
final class WhatsAppExportController
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __invoke(Request $request, WhatsAppConnection $connection): Response
    {
        $connection->authorize($request->user());
        $line = ($request->integer('line') ?: null) === null ? null : WhatsAppBackupLine::query()->findOrFail($request->integer('line'));
        $integrationId = $connection->integration()?->id;
        abort_if($line === null && $integrationId === null, 404);
        $only = $request->integer('conversation') ?: null;
        $conversations = WhatsAppExport::conversations($line, $integrationId, $only);
        abort_if($only !== null && ! (clone $conversations)->exists(), 404);
        $name = WhatsAppExport::fileName($line, $only);

        if ($only !== null || WhatsAppExport::messageCount($conversations) <= WhatsAppExport::inlineLimit()) {
            $path = (string) tempnam(sys_get_temp_dir(), 'wa-export');
            WhatsAppExport::write($conversations, $path);

            return response()->download($path, $name, ['Content-Type' => self::XLSX, 'Cache-Control' => 'no-store, private'])
                ->deleteFileAfterSend();
        }

        // One background build per person and scope: a second click while it runs waits for the same file.
        $userId = (int) $request->user()->getKey();
        $slot = 'whatsapp-export-slot:'.$userId.':'.($line?->id ?? 'inbox');
        $token = Cache::get($slot);
        $state = is_string($token) ? WhatsAppExport::state($token) : null;
        if ($state === null || $state['status'] !== 'pending' || now()->diffInMinutes($state['at'], true) > 20) {
            $token = (string) Str::uuid();
            WhatsAppExport::remember($token, ['user_id' => $userId, 'status' => 'pending', 'name' => $name, 'at' => now()->toIso8601String(), 'line_id' => $line?->id]);
            Cache::put($slot, $token, now()->addMinutes(20));
            BuildWhatsAppExport::dispatch($token, $line?->id);
        }

        return response()->view('operator.whatsapp.export-wait', [
            'title' => $line !== null ? $line->name : 'Tüm görüşmeler',
            'statusUrl' => route('operator.whatsapp.export.status', ['token' => $token]),
            'fileUrl' => route('operator.whatsapp.export.file', ['token' => $token]),
            'backUrl' => $line !== null ? route('operator.whatsapp', ['tab' => 'setup']) : route('operator.whatsapp'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function status(Request $request, string $token, WhatsAppConnection $connection): JsonResponse
    {
        $connection->authorize($request->user());
        $state = $this->owned($request, $token);
        $status = $state['status'] === 'ready' && ! File::exists(WhatsAppExport::path($token)) ? 'gone' : $state['status'];

        return response()->json(['status' => $status])->header('Cache-Control', 'no-store, private');
    }

    public function file(Request $request, string $token, WhatsAppConnection $connection): BinaryFileResponse
    {
        $connection->authorize($request->user());
        $state = $this->owned($request, $token);
        $path = WhatsAppExport::path($token);
        abort_unless($state['status'] === 'ready' && File::exists($path), 404);
        WhatsAppExport::remember($token, [...$state, 'status' => 'downloaded']);

        return response()->download($path, $state['name'], ['Content-Type' => self::XLSX, 'Cache-Control' => 'no-store, private'])
            ->deleteFileAfterSend();
    }

    /** @return array{user_id: int, status: string, name: string, at: string, line_id: ?int} */
    private function owned(Request $request, string $token): array
    {
        $state = WhatsAppExport::state($token);
        abort_if($state === null || (int) $state['user_id'] !== (int) $request->user()->getKey(), 404);

        return $state;
    }
}
