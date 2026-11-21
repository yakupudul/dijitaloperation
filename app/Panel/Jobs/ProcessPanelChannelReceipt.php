<?php

namespace App\Panel\Jobs;

use App\Panel\Services\PanelChannels;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Turns one stored Meta event of a brand's channel into messages and leads. */
class ProcessPanelChannelReceipt implements ShouldBeUnique, ShouldQueue
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
        return 'panel-receipt:'.$this->receiptId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(PanelChannels $channels): void
    {
        $channels->process($this->receiptId);
    }
}
