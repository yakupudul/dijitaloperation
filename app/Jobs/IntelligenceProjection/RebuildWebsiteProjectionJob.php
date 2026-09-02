<?php

namespace App\Jobs\IntelligenceProjection;

use App\Models\DigitalAsset;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RebuildWebsiteProjectionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $websiteAssetId,
        public readonly string $trigger = 'collection_completed',
        public readonly ?int $triggerCollectionRunId = null,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'website-projection:'.$this->websiteAssetId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WebsiteProjectionRebuilder $rebuilder): void
    {
        $asset = DigitalAsset::query()->with('brand')->where('type', 'website')->find($this->websiteAssetId);
        if (! $asset instanceof DigitalAsset) {
            return;
        }

        $rebuilder->rebuild(
            asset: $asset,
            trigger: $this->trigger,
            triggerCollectionRunId: $this->triggerCollectionRunId,
            periodStart: $this->periodStart !== null ? CarbonImmutable::parse($this->periodStart, 'UTC') : null,
            periodEnd: $this->periodEnd !== null ? CarbonImmutable::parse($this->periodEnd, 'UTC') : null,
        );
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
