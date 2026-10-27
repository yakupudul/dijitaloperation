<?php

namespace App\Jobs\Site;

use App\Services\Site\Showcase\SiteQuestions;
use App\Services\Site\Showcase\SiteShowcase;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the brand switch, the Google reviews and the published questions to one site (connector ≥ 1.28.0), then asks
 * the questions of the assigned pages that still have room. A write to the site, so it runs on the writes queue.
 */
final class SyncSiteShowcaseJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 120;

    public function __construct(public int $siteId)
    {
        $this->onQueue((string) config('queue.writes_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300];
    }

    public function handle(SiteShowcase $showcase, SiteQuestions $questions): void
    {
        try {
            $result = $showcase->sync($this->siteId);
        } catch (Throwable $exception) {
            Log::warning('Site showcase sync failed', ['site_id' => $this->siteId, 'error' => mb_substr($exception->getMessage(), 0, 300)]);

            throw $exception;
        }
        if (($result['targets'] ?? []) !== []) {
            $questions->plan($this->siteId, $result['targets']);
        }
    }
}
