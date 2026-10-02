<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\DataCenter\DataCenterEraser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Veri merkezi: deletes the selected data sets of one source in the background (large tables can take a while). */
final class EraseSourceDataJob implements ShouldQueue
{
    use Queueable;

    /** Below the queue retry_after (900s) so a slow delete is never picked up twice. */
    public int $timeout = 870;

    public int $tries = 1;

    /** @param  list<string>  $datasets */
    public function __construct(public string $kind, public int $sourceId, public array $datasets, public ?int $actorId = null) {}

    public function handle(DataCenterEraser $eraser): void
    {
        $eraser->erase($this->kind, $this->sourceId, $this->datasets, $this->actorId !== null ? User::query()->find($this->actorId) : null);
    }
}
