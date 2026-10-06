<?php

namespace App\Jobs\Gbp;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Gbp\Desk\BranchPages;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Writes the branch page of one Business Profile (ADR-079). Can be delegated to Claude: the job then runs again once
 * Claude answered. The outcome is kept for the screen (running → ready | failed).
 */
final class PrepareBranchPageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public static function stateKey(int $assetId): string
    {
        return 'gbp-branch-page:'.$assetId;
    }

    public function handle(BranchPages $pages, AiTaskQueue $tasks): void
    {
        $asset = DigitalAsset::query()->with('brand.customer')->find($this->assetId);
        if ($asset === null) {
            return;
        }
        $tasks->begin(new self($this->assetId), $asset->brand_id !== null ? (int) $asset->brand_id : null, 'Şube sayfası · '.($asset->brand?->name ?? $asset->name));
        try {
            $result = $pages->prepare($asset);
            Cache::put(self::stateKey($this->assetId), $result === null
                ? ['status' => 'running', 'message' => 'Claude sırasında; yazınca burada görünür.']
                : ['status' => 'ready', 'message' => $result['issues'] > 0 ? 'Sayfa hazır; '.$result['issues'].' ifade sektör kuralına takıldı, okuyup düzeltin.' : 'Sayfa hazır; okuyup gönderin.'], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($this->assetId), ['status' => 'failed', 'message' => mb_substr($exception->getMessage(), 0, 300)], now()->addDay());
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(self::stateKey($this->assetId), ['status' => 'failed', 'message' => 'Sayfa hazırlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
