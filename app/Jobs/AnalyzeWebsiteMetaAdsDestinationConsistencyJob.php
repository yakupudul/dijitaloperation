<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\CrossAssetWebsiteMetaAdsDestinationConsistencyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeWebsiteMetaAdsDestinationConsistencyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DigitalAsset $digitalAsset) {}

    public function handle(CrossAssetWebsiteMetaAdsDestinationConsistencyService $service): Run
    {
        return $service->analyze($this->digitalAsset);
    }
}
