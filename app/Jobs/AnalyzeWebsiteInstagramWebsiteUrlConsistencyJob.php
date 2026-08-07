<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\CrossAssetWebsiteInstagramWebsiteUrlConsistencyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeWebsiteInstagramWebsiteUrlConsistencyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DigitalAsset $digitalAsset) {}

    public function handle(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService $service): Run
    {
        return $service->analyze($this->digitalAsset);
    }
}
