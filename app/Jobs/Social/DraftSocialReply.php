<?php

namespace App\Jobs\Social;

use App\Models\SocialThread;
use App\Services\Social\SocialReplyWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Writes the reply draft of one social inbox thread in the background. */
class DraftSocialReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 175;

    public int $tries = 1;

    public function __construct(public int $threadId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'social-reply:'.$this->threadId;
    }

    public function handle(SocialReplyWriter $writer): void
    {
        $writer->write($this->threadId, 80, $this);
    }

    public function failed(?Throwable $exception): void
    {
        SocialThread::query()->whereKey($this->threadId)->whereIn('draft_status', ['queued', 'running'])
            ->update(['draft_status' => 'failed', 'draft_note' => 'Yazma işi yarıda kaldı; "Yeniden yaz" ile tekrar dene.']);
    }
}
