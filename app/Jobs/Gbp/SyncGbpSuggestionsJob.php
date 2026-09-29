<?php

namespace App\Jobs\Gbp;

use App\Models\DigitalAsset;
use App\Services\Gbp\GbpSuggestions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** İşletme Profili system checks: the failing profile standards → suggestions (no AI). Daily and on "Yeniden kontrol et". */
final class SyncGbpSuggestionsJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(GbpSuggestions $suggestions): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        if ($asset !== null) {
            $suggestions->syncStandards($asset);
        }
    }
}
