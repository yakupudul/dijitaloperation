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
 * operator click. Each can be delegated to Claude: the job then runs again once Claude answered.
 */
final class RunGbpAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Turkish subject of the Claude task, by operation. */
    private const array SUBJECTS = [
        GbpAssistant::OP_SERVICES => 'İşletme Profili hizmet karşılaştırması',
        GbpAssistant::OP_DESCRIPTION => 'İşletme Profili açıklama önerisi',
        GbpAssistant::OP_POST => 'İşletme Profili sayfadan gönderi',
        GbpAssistant::OP_PROFILE => 'İşletme Profili kategori ve hizmetler',
    ];

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
        $asset = DigitalAsset::query()->with('brand')->find($this->assetId);
        $tasks->begin(new self($this->assetId, $this->operation, $this->params), $asset?->brand_id !== null ? (int) $asset->brand_id : null,
            (self::SUBJECTS[$this->operation] ?? 'İşletme Profili').' · '.($asset?->brand?->name ?? $this->assetId));
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
