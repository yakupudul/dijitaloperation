<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\User;
use App\Services\Operator\BrandPageReader;
use App\Services\Operator\KomutaReader;
use App\Services\Repair\RepairDesk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Komuta "Hepsini onayla" (yakup 2026-10-22, "hemen 500 hatası verdi… 100 markada düşünemiyorum"): the click only
 * queues this job; finding a group's rows across every brand and marking thousands of them runs here, off the web
 * request (also a brand card's "Hepsini çöz", Aşama 6). The rows then go out through ApproveRepairRowsJob in small steps, as one Akış row.
 */
final class ApproveKomutaGroupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $groupKey, public int $userId) {}

    /** A brand card's "Hepsini çöz" (Aşama 6): "brand:{id}:{channel}" sends that channel's ready rows of one brand. */
    public static function brandKey(int $brandId, string $channel): string
    {
        return 'brand:'.$brandId.':'.$channel;
    }

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
            $ids = $this->ids($komuta);
            if ($ids !== []) {
                $desk->queueApproval($ids, $user);
            }
        } finally {
            Cache::forget(self::lockKey($this->groupKey));
        }
    }

    /** @return list<int> */
    private function ids(KomutaReader $komuta): array
    {
        if (preg_match('/^brand:(\d+):([a-z_]+)$/', $this->groupKey, $m) === 1) {
            $brand = Brand::query()->find((int) $m[1]);

            return $brand !== null ? app(BrandPageReader::class)->readyIds($brand, $m[2]) : [];
        }

        return $komuta->groupIds($this->groupKey);
    }
}
