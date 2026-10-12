<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\Site\ContentPosts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Hourly: asks one site where the articles MoxDOP sent stand (draft, scheduled, published) for Taslakta / Yayında. */
final class SyncContentPostsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3000;

    public function __construct(public int $siteId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(ContentPosts $posts): void
    {
        $site = DigitalAsset::query()->find($this->siteId);
        try {
            if ($site !== null) {
                $posts->sync($site);
            }
        } catch (Throwable) {
            // The site did not answer: the content snapshot keeps telling until the next hour.
        }
    }
}
