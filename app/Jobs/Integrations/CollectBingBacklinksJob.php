<?php

namespace App\Jobs\Integrations;

use App\Services\Integrations\Bing\BingWebmasterClient;
use App\Services\Site\Backlinks\BingBacklinkSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Saving the Bing key or "Şimdi çek" also reads the matched sites' inbound links at once (yakup 2026-11-07: the key was
 * just connected), instead of waiting for Sunday's weekly round, one job per site. Read only.
 */
final class CollectBingBacklinksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 850;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public int $assetId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(BingBacklinkSync $sync): void
    {
        if (BingWebmasterClient::configured()) {
            $sync->collect($this->assetId);
        }
    }

    /** One job per matched, verified site (each fits the heavy worker's time limit). */
    public static function dispatchAll(): int
    {
        $ids = DB::table('bing_sites')->where('verified', true)->pluck('digital_asset_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($ids as $id) {
            self::dispatch($id);
        }

        return count($ids);
    }
}
