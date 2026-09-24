<?php

namespace App\Jobs\Assistant;

use App\Models\DigitalAsset;
use App\Services\Assistant\UptimeMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class UptimeCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 240;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return 'uptime:'.$this->assetId;
    }

    public function handle(UptimeMonitor $monitor): void
    {
        $site = DigitalAsset::query()->operational()->where('type', 'website')->find($this->assetId);
        if ($site !== null) {
            $monitor->check($site);
        }
    }
}
