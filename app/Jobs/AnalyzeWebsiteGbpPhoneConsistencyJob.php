<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\CrossAssetWebsiteGbpPhoneConsistencyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeWebsiteGbpPhoneConsistencyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DigitalAsset $digitalAsset) {}

    public function handle(CrossAssetWebsiteGbpPhoneConsistencyService $service): Run
    {
        return $service->analyze($this->digitalAsset);
    }
}
