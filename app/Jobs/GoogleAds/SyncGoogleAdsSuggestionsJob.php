<?php

namespace App\Jobs\GoogleAds;

use App\Models\DigitalAsset;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Google Ads system checks (no AI) → suggestions. Daily and on "Yeniden kontrol et". */
final class SyncGoogleAdsSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(GoogleAdsSuggestions $suggestions): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        if ($asset !== null && $asset->brand_id !== null) {
            $suggestions->syncChecks($asset);
        }
    }
}
