<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Operator\KomutaReader;
use App\Services\Repair\RepairDesk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Komuta "Hepsini onayla" (yakup 2026-10-22, "hemen 500 hatası verdi… 100 markada düşünemiyorum"): the click only
 * queues this job; finding a group's rows across every brand and marking thousands of them runs here, off the web
 * request. The rows then go out through ApproveRepairRowsJob in small steps, as one Akış row.
 */
final class ApproveKomutaGroupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $groupKey, public int $userId) {}

    public static function lockKey(string $groupKey): string
    {
        return 'komuta-approve:'.$groupKey;
    }

    public function handle(KomutaReader $komuta, RepairDesk $desk): void
    {
        try {
            $user = User::query()->find($this->userId);
            if ($user === null) {
                return;
            }
            $ids = $komuta->groupIds($this->groupKey);
            if ($ids !== []) {
                $desk->queueApproval($ids, $user);
            }
        } finally {
            Cache::forget(self::lockKey($this->groupKey));
        }
    }
}
