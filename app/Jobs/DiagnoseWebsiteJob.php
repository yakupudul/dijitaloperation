<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\WebsiteDiagnosisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DiagnoseWebsiteJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DigitalAsset $digitalAsset) {}

    public function handle(WebsiteDiagnosisService $websiteDiagnosisService): Run
    {
        return $websiteDiagnosisService->diagnose($this->digitalAsset);
    }
}
