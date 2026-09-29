<?php

namespace App\Jobs\Meta;

use App\Services\Meta\MetaAssistant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** One Meta AI operation (creatives, campaign structure, form / landing) on an operator click. */
final class RunMetaAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $assetId, public string $operation) {}

    public function uniqueId(): string
    {
        return $this->assetId.':'.$this->operation;
    }

    public function handle(MetaAssistant $assistant): void
    {
        $assistant->run($this->assetId, $this->operation);
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(MetaAssistant::stateKey($this->assetId, $this->operation), ['status' => 'failed', 'message' => 'İşlem tamamlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
