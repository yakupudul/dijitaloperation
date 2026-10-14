<?php

namespace App\Jobs\GoogleAds;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\GoogleAds\GoogleAdsAssistant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One Google Ads AI operation (search terms, structure, ad texts) on an operator click. Each can be delegated to
 * Claude over MCP: the job then runs again once Claude answered.
 */
final class RunGoogleAdsAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Turkish subject of the Claude task, by operation. */
    private const array SUBJECTS = [
        GoogleAdsAssistant::OP_TERMS => 'Google Ads arama terimi incelemesi',
        GoogleAdsAssistant::OP_STRUCTURE => 'Google Ads kampanya yapısı',
        GoogleAdsAssistant::OP_ADS => 'Google Ads reklam metni',
    ];

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /** @param  array{terms?: list<string>, ad_group?: string}  $params */
    public function __construct(public int $assetId, public string $operation, public array $params = []) {}

    public function uniqueId(): string
    {
        return $this->assetId.':'.$this->operation;
    }

    public function handle(GoogleAdsAssistant $assistant, AiTaskQueue $tasks): void
    {
        $asset = DigitalAsset::query()->with('brand')->find($this->assetId);
        $tasks->begin(new self($this->assetId, $this->operation, $this->params), $asset?->brand_id !== null ? (int) $asset->brand_id : null,
            (self::SUBJECTS[$this->operation] ?? 'Google Ads').' · '.($asset?->brand?->name ?? $this->assetId));
        try {
            $assistant->run($this->assetId, $this->operation, $this->params);
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(GoogleAdsAssistant::stateKey($this->assetId, $this->operation), ['status' => 'failed', 'message' => 'İşlem tamamlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
