<?php

namespace App\Jobs;

use App\Services\Gbp\GbpPostDrafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Drafts one Business Profile post (operator clicked "AI ile taslak" on the Gönderiler tab). */
final class DraftGbpPostJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $assetId, public string $topic = '') {}

    public function handle(GbpPostDrafter $drafter): void
    {
        $drafter->write($this->assetId, $this->topic);
    }
}
