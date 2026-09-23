<?php

namespace App\Jobs;

use App\Models\AdvisorItem;
use App\Services\Advisor\Gbp\GbpProfileDrafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Operator-requested Business Profile description draft. One attempt; failures are stored on the item.
 */
final class DraftGbpProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $itemId) {}

    public function handle(GbpProfileDrafter $drafter): void
    {
        $item = AdvisorItem::query()->find($this->itemId);
        if ($item !== null) {
            $drafter->draft($item);
        }
    }
}
