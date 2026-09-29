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
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Faz 5: recomputes the URL karnesi (website_url_verdicts) of one website from stored data. Started by the manual
 * "Yenile" button (with an Activity run), after a projection rebuild, after an SEO plan and weekly.
 *
 * Production failed with LockTimeoutException ×15 (every trigger blocked 30 s on the refresh lock, then failed),
 * MaxAttemptsExceeded (timeout 900 s = retry_after 900 s) and re-dispatch storms. Now: one pending automatic
 * refresh per website (unique until it starts, debounced by UrlAuditService::dispatchFor), a non-blocking lock —
 * an automatic trigger that finds a refresh running asks for ONE re-run afterwards instead of waiting, a manual
 * click is released and retried a minute later — and a timeout well below the queue's retry_after.
 */
final class RefreshUrlVerdictsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Releases while another refresh runs count as attempts; exceptions and timeouts fail at once. */
    public int $tries = 10;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public int $websiteId, public string $trigger = 'manual', public ?int $runId = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'website-url-verdicts:'.$this->websiteId.':'.($this->runId ?? 'auto');
    }

    public static function rerunKey(int $websiteId): string
    {
        return 'website-url-verdicts:rerun:'.$websiteId;
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
        if ($result['status'] === 'busy') {
            if ($run !== null) {
                // The operator's click is kept: try again once the running refresh finished.
                $async->setPhase($run, 'waiting', 'Başka bir yenileme sürüyor; bitince başlayacak');
                $this->release(60);

                return;
            }
            // Automatic trigger while a refresh runs: remember it, the running one re-runs once when it ends.
            Cache::put(self::rerunKey($this->websiteId), $this->trigger, now()->addHours(2));

            return;
        }
        if ($run !== null) {
            $counts = $result['counts'];
            $async->markFinished($run, $result['status'] === 'completed' ? 'completed' : 'failed', $result['status'] === 'completed' ? 'Sayfa Karnesi güncellendi' : 'Hizmet kapsamı dışında', [
                'result_summary' => $result['urls'].' URL: '.($counts['fix'] ?? 0).' düzelt, '.($counts['merge'] ?? 0).' birleştir, '.($counts['strengthen'] ?? 0).' güçlendir, '
                    .($counts['deindex'] ?? 0).' dizinden çıkar, '.($counts['check'] ?? 0).' kontrol et, '.($counts['ok'] ?? 0).' sorun yok.',
                'provider_calls' => 0, 'ai_calls' => 0,
            ]);
        }
        if ($result['status'] === 'completed' && ($rerun = Cache::pull(self::rerunKey($this->websiteId))) !== null) {
            UrlAuditService::dispatchFor($this->websiteId, (string) $rerun);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        report($exception);
        WebsiteUrlAudit::query()->where('digital_asset_id', $this->websiteId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
        $run = $this->runId !== null ? Run::query()->find($this->runId) : null;
        if ($run !== null && ! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            app(AsyncOperationService::class)->markFailed($run, $exception);
        }
    }
}
