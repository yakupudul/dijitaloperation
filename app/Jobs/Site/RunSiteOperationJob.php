<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\SiteOperations;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One website-screen operation (categorize, service ↔ page, cluster ↔ page, summaries, URL analysis, AI ile yap,
 * standard proposal, weekly content, discovery, article) on the heavy queue. Unique per site × operation × subject;
 * each operation is idempotent and bounded. The screen reads the stored result and the status line only.
 * Eşleştir (cluster ↔ page) of a large site runs in parts: a part that ran out of time queues the next one
 * (params part = n), at most MAX_PARTS; one Eşleştir runs per site at a time whatever started it.
 */
final class RunSiteOperationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    /** Eşleştir parts of one pass at most (each part makes progress; this only stops a runaway chain). */
    public const int MAX_PARTS = 30;

    /** @param  array<string, mixed>  $params */
    public function __construct(public int $siteId, public string $operation, public array $params = [])
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->operation.':'.md5((string) json_encode($this->params));
    }

    /**
     * Whether a delegated call of the site × operation still waits for Claude (MCP): an open AI iş kuyruğu row of the
     * run handle() begins for this job with one of these params — or, with $answeredSince, Claude's answer given after
     * it that the job dispatched again has not taken yet. False when the MCP server is not configured (nothing can
     * answer).
     *
     * @param  list<array<string, mixed>>  $paramSets
     */
    public static function waitsForClaude(int $siteId, string $operation, array $paramSets = [[]], ?DateTimeInterface $answeredSince = null): bool
    {
        return AiTaskQueue::enabled()
            && AiTaskQueue::waits(array_map(fn (array $params): self => new self($siteId, $operation, $params), $paramSets), $answeredSince);
    }

    public function handle(SiteOperations $operations): void
    {
        $site = DigitalAsset::query()->where('type', 'website')->find($this->siteId);
        if ($site === null) {
            SiteOperations::putStatus($this->siteId, $this->operation, ['status' => 'no_site'], $this->params);

            return;
        }
        // Claude (MCP): a delegated call of this run waits in the AI iş kuyruğu; the same job runs again with the answer.
        $tasks = app(AiTaskQueue::class);
        $tasks->begin(new self($this->siteId, $this->operation, $this->params), $site->brand_id !== null ? (int) $site->brand_id : null, $this->subject($site));
        try {
            $this->run($operations, $site);
        } finally {
            $tasks->settle();
        }
    }

    private function run(SiteOperations $operations, DigitalAsset $site): void
    {
        if ($this->operation !== SiteOperations::CLUSTER_AUDIT) {
            SiteOperations::putStatus($this->siteId, $this->operation, $operations->run($site, $this->operation, $this->params), $this->params);

            return;
        }
        $lock = Cache::lock('site-op-lock:'.$this->siteId.':'.SiteOperations::CLUSTER_AUDIT, $this->timeout + 60);
        if (! $lock->get()) {
            return; // another Eşleştir of this site runs: it keeps the status
        }
        try {
            $result = $operations->run($site, $this->operation, $this->params);
        } finally {
            $lock->release();
        }
        $part = (int) ($this->params['part'] ?? 1);
        if (($result['status'] ?? null) === 'partial') {
            if ($part >= self::MAX_PARTS) {
                SiteOperations::putStatus($this->siteId, $this->operation, ['status' => 'stalled', 'part' => $part]);

                return;
            }
            SiteOperations::dispatch($this->siteId, $this->operation, ['part' => $part + 1]);

            return;
        }
        SiteOperations::putStatus($this->siteId, $this->operation, $result + ['part' => $part], $this->params);
    }

    /** Short subject of the AI iş kuyruğu row: the site and the suggestion or idea it is for. */
    private function subject(DigitalAsset $site): string
    {
        $for = isset($this->params['suggestion_id']) ? ' · öneri #'.$this->params['suggestion_id']
            : (isset($this->params['kind'], $this->params['id']) ? ' · fikir '.$this->params['kind'].'#'.$this->params['id'] : '');

        return (string) ($site->name ?? $site->id).$for;
    }

    public function failed(?Throwable $exception): void
    {
        SiteOperations::putStatus($this->siteId, $this->operation, ['status' => $exception instanceof TimeoutExceededException ? 'timeout' : 'error'], $this->params);
    }
}
