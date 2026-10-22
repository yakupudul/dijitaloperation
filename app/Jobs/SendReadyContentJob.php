<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkBatch;
use App\Services\Work\ContentAutopilot;
use App\Services\Work\WorkBatches;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Hepsini siteye gönder" (yakup 2026-10-20): every checked article (of one brand or site when given) goes to WordPress
 * as a draft with its translations, on the writes queue. One attempt; what could not be sent stays with its reason.
 */
final class SendReadyContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $userId, public ?int $brandId = null, public ?int $siteId = null)
    {
        $this->onQueue((string) config('queue.writes_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return ($this->brandId ?? 'all').'-'.($this->siteId ?? 'all');
    }

    public function handle(ContentAutopilot $content, ?WorkBatches $batches = null): void
    {
        $batches ??= app(WorkBatches::class);
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }
        // Akış: the click is one row there; it opens when the job starts (a second click while one runs is dropped).
        $total = $content->readyFor($this->brandId, $this->siteId);
        $batch = $batches->open(WorkBatch::KIND_CONTENT, 'Yazılar siteye gönderiliyor', $user, $total, ['channel' => 'web', 'brand_id' => $this->brandId, 'brand_count' => $this->brandId !== null ? 1 : 0]);
        $batches->start($batch);
        try {
            $result = WorkBatches::within($batch, fn (): array => $content->sendReady($user, $this->brandId, null, $this->siteId));
        } catch (Throwable $error) {
            $batches->finish($batch, mb_substr($error->getMessage(), 0, 200));

            throw $error;
        }
        $batches->step($batch, 0, array_map(fn (string $line): array => ['title' => Str::before($line, ':'), 'reason' => trim(Str::after($line, ':')) ?: $line], $result['failed']));
        $batches->finish($batch, null, $result['sent']);
    }
}
