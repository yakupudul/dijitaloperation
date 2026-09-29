<?php

namespace App\Jobs\Meta;

use App\Models\DigitalAsset;
use App\Services\Meta\MetaChecks;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Meta system checks → suggestions (no AI). Daily and on "Yeniden kontrol et". */
final class SyncMetaSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(MetaChecks $checks): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        if ($asset !== null && $asset->type === 'meta_ads') {
            $checks->sync($asset);
        }
    }
}
