<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Work\ContentAutopilot;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Hepsini siteye gönder" (yakup 2026-10-20): every checked article (of one brand or site when given) goes to WordPress
 * as a draft with its translations, on the writes queue. One attempt; what could not be sent stays with its reason.
 */
final class SendReadyContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $userId, public ?int $brandId = null, public ?int $siteId = null)
    {
        $this->onQueue((string) config('queue.writes_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return ($this->brandId ?? 'all').'-'.($this->siteId ?? 'all');
    }

    public function handle(ContentAutopilot $content): void
    {
        $user = User::query()->find($this->userId);
        if ($user !== null) {
            $content->sendReady($user, $this->brandId, null, $this->siteId);
        }
    }
}
