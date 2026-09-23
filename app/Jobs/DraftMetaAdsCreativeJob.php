<?php

namespace App\Jobs;

use App\Models\AdvisorItem;
use App\Services\Advisor\MetaAds\MetaAdsCreativeDrafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Operator-requested creative draft for one fatigued Meta ad. One attempt; failures are stored on the item.
 */
final class DraftMetaAdsCreativeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $itemId) {}

    public function handle(MetaAdsCreativeDrafter $drafter): void
    {
        $item = AdvisorItem::query()->find($this->itemId);
        if ($item !== null) {
            $drafter->draft($item);
        }
    }
}
