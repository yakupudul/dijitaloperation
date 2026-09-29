<?php

namespace App\Jobs\Gbp;

use App\Services\Gbp\GbpAssistant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** One İşletme Profili AI operation (services compare, description, post from page) on an operator click. */
final class RunGbpAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /** @param  array{page_id?: int}  $params */
    public function __construct(public int $assetId, public string $operation, public array $params = []) {}

    public function uniqueId(): string
    {
        return $this->assetId.':'.$this->operation;
    }

    public function handle(GbpAssistant $assistant): void
    {
        $assistant->run($this->assetId, $this->operation, $this->params);
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(GbpAssistant::stateKey($this->assetId, $this->operation), ['status' => 'failed', 'message' => 'İşlem tamamlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
