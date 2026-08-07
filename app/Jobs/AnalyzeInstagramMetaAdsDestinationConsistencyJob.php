<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\CrossAssetInstagramMetaAdsDestinationConsistencyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeInstagramMetaAdsDestinationConsistencyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DigitalAsset $digitalAsset) {}

    public function handle(CrossAssetInstagramMetaAdsDestinationConsistencyService $service): Run
    {
        return $service->analyze($this->digitalAsset);
    }
}
