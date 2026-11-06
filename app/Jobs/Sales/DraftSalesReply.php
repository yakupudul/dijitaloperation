<?php

namespace App\Jobs\Sales;

use App\Models\SalesLead;
use App\Services\Sales\SalesReplyWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Writes a prospect's next message in the background (after a site scan, a new message from them). */
class DraftSalesReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 175; // the quick lane (under three minutes): Claude, then ChatGPT if it fails

    public int $tries = 1;

    public function __construct(public int $leadId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sales-reply:'.$this->leadId;
    }

    public function handle(SalesReplyWriter $writer): void
    {
        $writer->write($this->leadId, 80, $this);
    }

    public function failed(?Throwable $exception): void
    {
        SalesLead::query()->whereKey($this->leadId)->whereIn('draft_status', ['queued', 'running'])
            ->update(['draft_status' => 'failed', 'draft_note' => 'Yazma işi yarıda kaldı; "Yeniden yaz" ile tekrar dene.']);
    }
}
