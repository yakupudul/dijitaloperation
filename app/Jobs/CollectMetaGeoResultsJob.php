<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Services\MetaAds\MetaGeoResults;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Meta country + city results of one ad account (read-only Insights). */
final class CollectMetaGeoResultsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public int $assetId, public ?int $days = null) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public static function stateKey(int $assetId): string
    {
        return 'meta-geo-results:'.$assetId;
    }

    public function handle(MetaGeoResults $results): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        if ($asset === null || ! $asset->isOperational()) {
            return;
        }
        try {
            $rows = $results->collect($asset, $this->days);
            Cache::put(self::stateKey($this->assetId), ['state' => 'done', 'rows' => $rows, 'at' => now()->toIso8601String()], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($this->assetId), ['state' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 200), 'at' => now()->toIso8601String()], now()->addDay());
            report($exception);
        }
    }
}
