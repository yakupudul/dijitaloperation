<?php

namespace App\Jobs\Ads;

use App\Models\DigitalAsset;
use App\Services\Ads\AdServiceStats;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Rebuilds the 30-day per-service numbers of one Meta / Google Ads account, website or Business Profile (rules only, no AI). */
final class RefreshAdServiceStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public int $uniqueFor = 1800;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(AdServiceStats $stats): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        if ($asset !== null) {
            $stats->refresh($asset);
        }
    }
}
