<?php

namespace App\Jobs\WhatsApp;

use App\Models\WhatsAppWebhookReceipt;
use App\Services\WhatsApp\WhatsAppIngestion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWhatsAppReceipt implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;
    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(public int $receiptId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'wa-receipt:'.$this->receiptId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WhatsAppIngestion $ingestion): void
    {
        $ingestion->process($this->receiptId);
    }

    public function failed(?Throwable $exception): void
    {
        WhatsAppWebhookReceipt::query()->whereKey($this->receiptId)->where('status', 'pending')
            ->update(['status' => 'failed', 'error_code' => 'ingestion_failed', 'updated_at' => now()]);
    }
}
