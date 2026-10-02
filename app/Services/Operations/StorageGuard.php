<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps a full disk from taking the whole system down. When free space on the database disk drops below the
 * pause threshold, collection jobs wait (without using attempts) instead of writing until PostgreSQL stops;
 * the watchdog pushes a phone alert well before that (warn threshold).
 */
final class StorageGuard
{
    /** @return array{free_bytes: float, total_bytes: float, share: float} */
    public function disk(): array
    {
        return Cache::remember('storage-guard:disk', 60, function (): array {
            $dir = '/';
            try {
                if (DB::getDriverName() === 'pgsql') {
                    $dir = (string) DB::selectOne("select current_setting('data_directory') as d")->d;
                }
            } catch (Throwable) {
                $dir = '/';
            }
            $dir = is_dir($dir) ? $dir : base_path();
            $free = (float) (@disk_free_space($dir) ?: 0);
            $total = (float) (@disk_total_space($dir) ?: 0);

            return ['free_bytes' => $free, 'total_bytes' => $total, 'share' => $total > 0 ? $free / $total : 1.0];
        });
    }

    /** Collection writes must wait: free space below the pause share or the pause bytes floor. */
    public function collectionPaused(): bool
    {
        $disk = $this->disk();
        if ($disk['total_bytes'] <= 0) {
            return false;
        }

        return $disk['share'] < (float) config('moxdop-observability.storage.pause_share', 0.06)
            || $disk['free_bytes'] < (float) config('moxdop-observability.storage.pause_gb', 3) * 1e9;
    }

    /** The watchdog should warn (earlier than the pause). */
    public function warning(): ?string
    {
        $disk = $this->disk();
        if ($disk['total_bytes'] <= 0 || $disk['share'] >= (float) config('moxdop-observability.storage.warn_share', 0.15)) {
            return null;
        }

        return sprintf('Disk dolmak üzere: %.1f GB boş (%%%d).%s', $disk['free_bytes'] / 1e9, (int) round($disk['share'] * 100),
            $this->collectionPaused() ? ' Veri toplama yer açılana kadar bekletiliyor.' : ' Yer açılmazsa veri toplama duracak.');
    }
}
