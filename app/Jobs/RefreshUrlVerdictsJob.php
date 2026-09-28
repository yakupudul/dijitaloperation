<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\WebsiteUrlAudit;
use App\Services\Async\AsyncOperationService;
use App\Services\Website\UrlAudit\UrlAuditService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Faz 5: recomputes the URL karnesi (website_url_verdicts) of one website from stored data. Started by the manual
 * "Yenile" button (with an Activity run), after a projection rebuild, after an SEO plan and weekly.
 */
final class RefreshUrlVerdictsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $websiteId, public string $trigger = 'manual', public ?int $runId = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'website-url-verdicts:'.$this->websiteId.':'.($this->runId ?? 'auto');
    }

    public function handle(UrlAuditService $audit, AsyncOperationService $async): void
    {
        $run = $this->runId !== null ? Run::query()->find($this->runId) : null;
        if ($async->skippedOutsideServiceScope($run)) {
            WebsiteUrlAudit::query()->where('digital_asset_id', $this->websiteId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error' => 'Hizmet kapsamı dışında.']);

            return;
        }
        $site = DigitalAsset::query()->with('brand')->where('type', 'website')->find($this->websiteId);
        if ($site === null) {
            return;
        }
        if ($run !== null) {
            $async->markRunning($run, 'building_url_verdicts', 'URL kararları hesaplanıyor');
        }
        $result = $audit->refresh($site, $this->trigger);
        if ($run !== null) {
            $counts = $result['counts'];
            $async->markFinished($run, $result['status'] === 'completed' ? 'completed' : 'failed', $result['status'] === 'completed' ? 'Sayfa Karnesi güncellendi' : 'Hizmet kapsamı dışında', [
                'result_summary' => $result['urls'].' URL: '.($counts['fix'] ?? 0).' düzelt, '.($counts['merge'] ?? 0).' birleştir, '.($counts['strengthen'] ?? 0).' güçlendir, '
                    .($counts['deindex'] ?? 0).' dizinden çıkar, '.($counts['check'] ?? 0).' kontrol et, '.($counts['ok'] ?? 0).' sorun yok.',
                'provider_calls' => 0, 'ai_calls' => 0,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        report($exception);
        $run = $this->runId !== null ? Run::query()->find($this->runId) : null;
        if ($run !== null && ! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            app(AsyncOperationService::class)->markFailed($run, $exception);
        }
    }
}
