<?php

namespace App\Jobs\Finance;

use App\Services\Finance\ReceiptCapture;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A WhatsApp photo or document from a payer is read as a possible dekont (see ReceiptCapture). */
final class FinanceReceiptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $messageId)
    {
        $this->onQueue('default');
    }

    public function handle(ReceiptCapture $capture): void
    {
        $capture->handle($this->messageId);
    }
}
