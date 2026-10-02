<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Services\Alerts\AdBudgetWatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Budget watch: read-only budget / balance / delivery check of one Google Ads or Meta account. */
final class CheckAdBudgetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3000;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(AdBudgetWatch $watch): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        if ($asset !== null && $asset->isOperational()) {
            $watch->check($asset);
        }
    }
}
