<?php

namespace App\Jobs;

use App\Models\SeoPlan;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Services\Website\UrlAudit\UrlAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued SEO plan (see SeoPlanRunner). One attempt; failure is recorded on the plan row.
 */
final class RunSeoPlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $planId) {}

    public function handle(SeoPlanRunner $runner): void
    {
        $runner->run($this->planId);
        // Faz 5: refresh the URL karnesi with the plan's new SEO tasks.
        $siteId = SeoPlan::query()->whereKey($this->planId)->where('status', SeoPlan::STATUS_COMPLETED)->value('digital_asset_id');
        if ($siteId !== null) {
            UrlAuditService::dispatchFor((int) $siteId, 'seo_plan');
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        app(SeoPlanRunner::class)->markFailed($this->planId, $exception);
    }
}
