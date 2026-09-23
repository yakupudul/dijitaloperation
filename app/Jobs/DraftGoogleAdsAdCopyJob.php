<?php

namespace App\Jobs;

use App\Models\AdvisorItem;
use App\Services\Advisor\GoogleAds\GoogleAdsAdCopyDrafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Operator-requested ad copy draft for one advisor item. One attempt; failures are stored on the item.
 */
final class DraftGoogleAdsAdCopyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $itemId) {}

    public function handle(GoogleAdsAdCopyDrafter $drafter): void
    {
        $item = AdvisorItem::query()->find($this->itemId);
        if ($item !== null) {
            $drafter->draft($item);
        }
    }
}
