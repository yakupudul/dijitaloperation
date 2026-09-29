<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\Site\SiteOperations;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One website-screen operation (categorize, service ↔ page, cluster ↔ page, summaries, URL analysis, AI ile yap,
 * standard proposal, weekly content, discovery, article) on the heavy queue. Unique per site × operation × subject;
 * each operation is idempotent and bounded. The screen reads the stored result and the status line only.
 */
final class RunSiteOperationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    /** @param  array<string, mixed>  $params */
    public function __construct(public int $siteId, public string $operation, public array $params = [])
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->operation.':'.md5((string) json_encode($this->params));
    }

    public function handle(SiteOperations $operations): void
    {
        $site = DigitalAsset::query()->where('type', 'website')->find($this->siteId);
        $result = $site !== null ? $operations->run($site, $this->operation, $this->params) : ['status' => 'no_site'];
        SiteOperations::putStatus($this->siteId, $this->operation, $result, $this->params);
    }

    public function failed(?Throwable $exception): void
    {
        SiteOperations::putStatus($this->siteId, $this->operation, ['status' => 'error'], $this->params);
    }
}
