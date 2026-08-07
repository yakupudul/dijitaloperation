<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\CrossAssetWebsiteGbpAddressConsistencyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeWebsiteGbpAddressConsistencyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DigitalAsset $digitalAsset) {}

    public function handle(CrossAssetWebsiteGbpAddressConsistencyService $service): Run
    {
        return $service->analyze($this->digitalAsset);
    }
}
