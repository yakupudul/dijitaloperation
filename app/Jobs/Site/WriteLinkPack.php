<?php

namespace App\Jobs\Site;

use App\Models\BacklinkSource;
use App\Services\Site\Backlinks\LinkPackWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Writes the application material of one Bağlantı masası row in the background (can wait for the subscription Claude). */
class WriteLinkPack implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 175;

    public int $tries = 1;

    public function __construct(public int $rowId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'link-pack:'.$this->rowId;
    }

    public function handle(LinkPackWriter $writer): void
    {
        $writer->write($this->rowId, $this);
    }

    public function failed(?Throwable $exception): void
    {
        BacklinkSource::query()->whereKey($this->rowId)->whereIn('material_status', ['queued', 'running'])
            ->update(['material_status' => 'failed', 'material_note' => 'Yazma işi yarıda kaldı; "Yeniden yaz" ile tekrar dene.']);
    }
}
