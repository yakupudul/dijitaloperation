<?php

namespace App\Jobs\Meta;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Meta\MetaStrategy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Strateji öner › "Plan taslağı iste": one plan draft for one brand service and result type (can wait for Claude: the
 * job then runs again once Claude answered). The plan lands in the brand's Meta Yapılacaklar; the state is kept for
 * the Strateji screen (running → ready | failed).
 */
final class DraftMetaStrategyPlanJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public int $brandId, public int $assetId, public int $serviceId, public string $type, public string $city) {}

    public function uniqueId(): string
    {
        return $this->brandId.':'.$this->serviceId.':'.$this->type;
    }

    public function handle(MetaStrategy $strategy, AiTaskQueue $tasks): void
    {
        $key = MetaStrategy::planStateKey($this->brandId, $this->serviceId, $this->type);
        $asset = DigitalAsset::query()->with('brand')->find($this->assetId);
        if ($asset === null || $asset->type !== 'meta_ads' || (int) $asset->brand_id !== $this->brandId) {
            Cache::put($key, ['status' => 'failed', 'message' => 'Meta reklam hesabı bulunamadı.'], now()->addDay());

            return;
        }
        $tasks->begin(new self($this->brandId, $this->assetId, $this->serviceId, $this->type, $this->city), $this->brandId, 'Meta strateji planı · '.($asset->brand?->name ?? $asset->name));
        try {
            $stored = $strategy->draftPlan($asset, $this->serviceId, $this->type, $this->city);
            Cache::put($key, $stored === null
                ? ['status' => 'running', 'message' => 'Claude sırasında; plan hazır olunca markanın Meta › Yapılacaklar listesine düşer.']
                : ['status' => 'ready', 'message' => 'Plan taslağı markanın Meta › Yapılacaklar listesinde.', 'asset_id' => $this->assetId], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put($key, ['status' => 'failed', 'message' => mb_substr($exception->getMessage(), 0, 300)], now()->addDay());
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(MetaStrategy::planStateKey($this->brandId, $this->serviceId, $this->type), ['status' => 'failed', 'message' => 'Plan taslağı hazırlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
