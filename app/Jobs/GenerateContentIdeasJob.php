<?php

namespace App\Jobs;

use App\Services\ContentStudio\ContentIdeaAi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Faz 4: "Konu üret" — ideas from the topic map first, then one AI call per batch for the gap. */
final class GenerateContentIdeasJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** @param  list<int>  $offeringIds */
    public function __construct(public int $siteId, public array $offeringIds, public int $count, public ?int $userId = null) {}

    public function handle(ContentIdeaAi $ai): void
    {
        $ai->run($this->siteId, $this->offeringIds, $this->count, $this->userId);
    }
}
