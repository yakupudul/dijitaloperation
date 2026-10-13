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
 * One attempt: an approval is never repeated blindly; a row that could not be sent returns to the desk with its reason.
 */
final class ApproveRepairRowsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param  list<int>  $ids */
    public function __construct(public array $ids, public int $userId) {}

    public function handle(RepairDesk $desk): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            $desk->releaseQueued($this->ids, 'Onaylayan kullanıcı bulunamadı; tekrar onaylayın.');

            return;
        }
        $result = ['failed_ids' => []];
        try {
            $result = $desk->approve($this->ids, $user);
        } finally {
            $desk->releaseQueued($this->ids, null, $result['failed_ids'] ?? []);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(RepairDesk::class)->releaseQueued($this->ids, 'Onay uygulanamadı ('.mb_substr((string) $exception?->getMessage(), 0, 160).'); tekrar onaylayın.');
    }
}
