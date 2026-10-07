<?php

namespace App\Jobs\Meta;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Meta\MetaCampaignServices;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Kampanya → hizmet with AI for the campaigns of one Meta account that no rule matched (one call, can wait for Claude:
 * the job then runs again once Claude answered). The outcome is kept for the screens (running → ready | failed).
 */
final class MatchMetaCampaignServicesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(MetaCampaignServices $services, AiTaskQueue $tasks): void
    {
        $asset = DigitalAsset::query()->with('brand')->find($this->assetId);
        if ($asset === null || $asset->type !== 'meta_ads') {
            return;
        }
        $tasks->begin(new self($this->assetId), $asset->brand_id !== null ? (int) $asset->brand_id : null, 'Meta kampanyalarına hizmet · '.($asset->brand?->name ?? $asset->name));
        try {
            $result = $services->runAi($asset);
            Cache::put(MetaCampaignServices::stateKey($this->assetId), $result === null
                ? ['status' => 'running', 'message' => 'Claude sırasında; eşleştirince burada görünür.']
                : ['status' => 'ready', 'message' => $result['asked'] === 0 ? 'AI’a sorulacak kampanya kalmadı.' : $result['suggested'].' / '.$result['asked'].' kampanyaya hizmet önerildi.'], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put(MetaCampaignServices::stateKey($this->assetId), ['status' => 'failed', 'message' => mb_substr($exception->getMessage(), 0, 300)], now()->addDay());
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(MetaCampaignServices::stateKey($this->assetId), ['status' => 'failed', 'message' => 'Eşleştirme yapılamadı; tekrar deneyin.'], now()->addDay());
    }
}
