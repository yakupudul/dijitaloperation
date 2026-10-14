<?php

namespace App\Jobs\Meta;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Meta\MetaAssistant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One Meta AI operation (creatives, campaign structure, form / landing) on an operator click. Each can be delegated to
 * Claude over MCP: the job then runs again once Claude answered.
 */
final class RunMetaAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Turkish subject of the Claude task, by operation. */
    private const array SUBJECTS = [
        MetaAssistant::OP_CREATIVES => 'Meta kreatif önerisi',
        MetaAssistant::OP_STRUCTURE => 'Meta kampanya yapısı',
        MetaAssistant::OP_LANDING => 'Meta form / açılış sayfası',
    ];

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $assetId, public string $operation) {}

    public function uniqueId(): string
    {
        return $this->assetId.':'.$this->operation;
    }

    public function handle(MetaAssistant $assistant, AiTaskQueue $tasks): void
    {
        $asset = DigitalAsset::query()->with('brand')->find($this->assetId);
        $tasks->begin(new self($this->assetId, $this->operation), $asset?->brand_id !== null ? (int) $asset->brand_id : null,
            (self::SUBJECTS[$this->operation] ?? 'Meta').' · '.($asset?->brand?->name ?? $this->assetId));
        try {
            $assistant->run($this->assetId, $this->operation);
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(MetaAssistant::stateKey($this->assetId, $this->operation), ['status' => 'failed', 'message' => 'İşlem tamamlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
