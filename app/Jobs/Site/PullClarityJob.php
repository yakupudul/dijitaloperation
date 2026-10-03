<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\Site\Clarity\ClarityCollector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily Clarity pull of one website (one API request) and its behaviour rules. */
final class PullClarityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(ClarityCollector $collector): void
    {
        $site = DigitalAsset::query()->find($this->assetId);
        if ($site !== null && $site->type === 'website') {
            $collector->pull($site);
        }
    }
}
