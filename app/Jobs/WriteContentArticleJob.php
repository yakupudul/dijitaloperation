<?php

namespace App\Jobs;

use App\Services\ContentStudio\ContentStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Faz 4: writes one studio article (AI), then queues its language versions. */
final class WriteContentArticleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(public int $articleId) {}

    public function handle(ContentStudio $studio): void
    {
        $studio->runWrite($this->articleId);
    }
}
