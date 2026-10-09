<?php

namespace App\Jobs\Gbp;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Gbp\GbpPostQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Plans the empty days of one location's automatic posts (ADR-078). Can be delegated to Claude: the job then runs
 * again once Claude answered. The outcome is kept for the screens (running → ready | failed).
 */
final class FillGbpPostQueueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public int $assetId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public static function stateKey(int $assetId): string
    {
        return 'gbp-post-queue:'.$assetId;
    }

    public function handle(GbpPostQueue $queue, AiTaskQueue $tasks): void
    {
        $asset = DigitalAsset::query()->with('brand.customer')->find($this->assetId);
        if ($asset === null) {
            return;
        }
        $tasks->begin(new self($this->assetId, $this->force), $asset->brand_id !== null ? (int) $asset->brand_id : null,
            'İşletme Profili gönderileri · '.($asset->brand?->name ?? $asset->name));
        try {
            $result = $queue->fill($asset, $this->force);
            $message = match ($result['status'] ?? null) {
                null => 'Claude sırasında; yazınca burada görünür.',
                'full' => 'Plan dolu.',
                'no_content' => 'Sitede kullanılabilecek yeni sayfa yok; '.$result['empty'].' gün boş.',
                default => $result['added'].' gönderi hazırlandı'.($result['empty'] > 0 ? '; '.$result['empty'].' gün boş (içerik yetmedi).' : '.'),
            };
            Cache::put(self::stateKey($this->assetId), ['status' => $result === null ? 'running' : 'ready', 'message' => $message,
                'result' => $result['status'] ?? null, 'empty' => $result['empty'] ?? null], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($this->assetId), ['status' => 'failed', 'message' => mb_substr($exception->getMessage(), 0, 300)], now()->addDay());
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(self::stateKey($this->assetId), ['status' => 'failed', 'message' => 'Plan hazırlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
