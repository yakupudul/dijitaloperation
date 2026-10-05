<?php

namespace App\Jobs\Gbp;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Gbp\GbpAssistant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One İşletme Profili AI operation (services compare, description, post from page, category / service plan) on an
 * operator click. The plan can be delegated to Claude: the job then runs again once Claude answered.
 */
final class RunGbpAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /** @param  array{page_id?: int, categories?: list<string>, services?: list<string>}  $params */
    public function __construct(public int $assetId, public string $operation, public array $params = []) {}

    public function uniqueId(): string
    {
        return $this->assetId.':'.$this->operation;
    }

    public function handle(GbpAssistant $assistant, AiTaskQueue $tasks): void
    {
        if ($this->operation !== GbpAssistant::OP_PROFILE) {
            $assistant->run($this->assetId, $this->operation, $this->params);

            return;
        }
        $asset = DigitalAsset::query()->with('brand')->find($this->assetId);
        $tasks->begin(new self($this->assetId, $this->operation, $this->params), $asset?->brand_id !== null ? (int) $asset->brand_id : null,
            'İşletme Profili kategori ve hizmetler · '.($asset?->brand?->name ?? $this->assetId));
        try {
            $assistant->run($this->assetId, $this->operation, $this->params);
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(GbpAssistant::stateKey($this->assetId, $this->operation), ['status' => 'failed', 'message' => 'İşlem tamamlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
