<?php

namespace App\Jobs;

use App\Services\ContentStudio\ContentStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Faz 4: localizes one studio article into another language of the site (AI). */
final class LocalizeContentArticleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(public int $articleId) {}

    public function handle(ContentStudio $studio): void
    {
        $studio->runLocalize($this->articleId);
    }
}
