<?php

namespace App\Jobs\Brand;

use App\Models\Brand;
use App\Services\Brand\BrandDossier;
use App\Services\Brand\BrandFacts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bilgi dosyası and Marka bilgi kartı built again soon after a brand's services or areas change, so the other tabs
 * (Özet, Bilgi dosyası, Claude's brand file) do not keep saying "hizmet yok" until the nightly rebuild.
 */
final class RefreshBrandFilesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public int $brandId)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    /** Many saves in a row (Otomatik kur, a bulk edit) end in one rebuild a minute later. */
    public static function soon(int $brandId): void
    {
        if (! config('moxdop.brand_files_live_refresh', true) || $brandId <= 0) {
            return;
        }
        self::dispatch($brandId)->delay(now()->addSeconds(60))->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->brandId;
    }

    public function handle(BrandDossier $dossier, BrandFacts $facts): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand === null) {
            return;
        }
        $dossier->build($brand);
        $facts->build($brand);
    }
}
