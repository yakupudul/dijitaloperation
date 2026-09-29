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

    /**
     * Below the queue's retry_after (1 800 s) so a slow plan is never handed to a second worker. A 5 000-page site
     * plans in seconds since the rule engine indexes page texts (TokenIndex) instead of comparing every query with
     * every page; the margin is for the database reads of very large sites.
     */
    public int $timeout = 900;

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
