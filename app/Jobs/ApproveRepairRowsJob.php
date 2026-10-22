<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Repair\RepairDesk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Onarım masası approval (yakup 2026-10-13, "onayladığımda 504"): the operator's click only marks the rows as queued;
 * this job runs the approval (checks, baseline, the logged and undoable external write requests) off the web request.
 *
 * Audit N6 (2026-10-22): the rows go in small steps; each step beats on the rows still waiting, so they stay "sırada"
 * while the job lives and are released the moment their step is done. A package that dies (timeout) is handed once
 * more to a new job for the rows it had not reached — a sent row is no longer open, so approve() never sends it twice.
 * Only after that second try do the rows return to the desk with their reason.
 */
final class ApproveRepairRowsJob implements ShouldQueue
{
    use Queueable;

    /** Rows approved in one step: small enough that a step never runs near the timeout. */
    public const int STEP = 50;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param  list<int>  $ids */
    public function __construct(public array $ids, public int $userId, public int $attempt = 1) {}

    public function handle(RepairDesk $desk): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            $desk->releaseQueued($this->ids, 'Onaylayan kullanıcı bulunamadı; tekrar onaylayın.');

            return;
        }
        // Rows released meanwhile (another job, a stale mark the operator approved again) are not this job's any more.
        $left = $desk->stillQueued($this->ids);
        while ($left !== []) {
            $desk->beat($left);
            $step = array_slice($left, 0, self::STEP);
            $result = ['failed_ids' => []];
            try {
                $result = $desk->approve($step, $user);
            } finally {
                $desk->releaseQueued($step, null, $result['failed_ids'] ?? []);
            }
            $left = array_slice($left, self::STEP);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $desk = app(RepairDesk::class);
        $left = $desk->stillQueued($this->ids);
        if ($left === []) {
            return;
        }
        if ($this->attempt < 2) {
            $desk->waitAgain($left);
            dispatch(new self($left, $this->userId, $this->attempt + 1))->onQueue((string) config('queue.writes_queue', 'default'));

            return;
        }
        $desk->releaseQueued($left, 'Onay uygulanamadı ('.mb_substr((string) $exception?->getMessage(), 0, 160).'); tekrar onaylayın.');
    }
}
