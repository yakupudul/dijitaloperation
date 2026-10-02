<?php

namespace App\Jobs;

use App\Services\Ai\Insights\AiInsightService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Writes one on-click AI insight (operator pressed its button). */
final class WriteAiInsightJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public string $kind, public int $subjectId) {}

    public function handle(AiInsightService $insights): void
    {
        $insights->write($this->kind, $this->subjectId);
    }
}
