<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Work\DailySend;
use App\Services\Work\WorkBatches;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Günlük gönderim: the Admin's daily click, run off the web request on the writes queue. One attempt; what could not
 * be sent stays where it was with its reason.
 */
final class SendDailyBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $userId, public ?int $batchId = null)
    {
        $this->onQueue((string) config('queue.writes_queue', 'default'));
    }

    public function handle(DailySend $daily): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            Cache::forget(DailySend::RUNNING_KEY);
            DailySend::clearProgress();
            app(WorkBatches::class)->finish($this->batchId, 'Gönderen kullanıcı bulunamadı.');

            return;
        }
        $daily->send($user, $this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget(DailySend::RUNNING_KEY);
        DailySend::clearProgress();
        app(WorkBatches::class)->finish($this->batchId, mb_substr((string) $exception?->getMessage(), 0, 200) ?: 'Gönderim yarıda kaldı.');
        Cache::put(DailySend::LAST_KEY, ['error' => mb_substr((string) $exception?->getMessage(), 0, 200), 'at' => now()->toIso8601String()], now()->addDays(7));
    }
}
