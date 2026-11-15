<?php

namespace App\Jobs\WhatsApp;

use App\Models\WhatsAppBackupLine;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

/** Writes a big WhatsApp Excel (a whole backup row or every inbox chat) off the request, on the heavy queue. */
class BuildWhatsAppExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 870;

    public int $tries = 1;

    public function __construct(public string $token, public ?int $lineId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(WhatsAppConnection $connection): void
    {
        $state = WhatsAppExport::state($this->token);
        if ($state === null) {
            return;
        }
        WhatsAppExport::prune();
        $line = $this->lineId !== null ? WhatsAppBackupLine::query()->find($this->lineId) : null;
        $integrationId = $connection->integration()?->id;
        if (($this->lineId !== null && $line === null) || ($line === null && $integrationId === null)) {
            WhatsAppExport::remember($this->token, [...$state, 'status' => 'failed']);

            return;
        }
        $path = WhatsAppExport::path($this->token);
        File::ensureDirectoryExists(dirname($path), 0700);
        WhatsAppExport::write(WhatsAppExport::conversations($line, $integrationId), $path);
        WhatsAppExport::remember($this->token, [...$state, 'status' => 'ready']);
    }

    public function failed(?Throwable $exception): void
    {
        File::delete(WhatsAppExport::path($this->token));
        $state = WhatsAppExport::state($this->token);
        if ($state !== null) {
            WhatsAppExport::remember($this->token, [...$state, 'status' => 'failed']);
        }
    }
}
