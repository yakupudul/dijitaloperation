<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\StorageGuard;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Disk guard: warns before the pause, pauses collection before the disk is full. */
final class StorageGuardTest extends TestCase
{
    private function disk(float $freeGb, float $totalGb): void
    {
        Cache::put('storage-guard:disk', ['free_bytes' => $freeGb * 1e9, 'total_bytes' => $totalGb * 1e9, 'share' => $freeGb / $totalGb], 60);
    }

    public function test_thresholds(): void
    {
        config(['moxdop-observability.storage' => ['warn_share' => 0.15, 'pause_share' => 0.06, 'pause_gb' => 3]]);
        $guard = app(StorageGuard::class);

        $this->disk(30, 75);
        $this->assertNull($guard->warning());
        $this->assertFalse($guard->collectionPaused());

        $this->disk(8, 75);
        $this->assertStringContainsString('Disk dolmak üzere', (string) $guard->warning());
        $this->assertFalse($guard->collectionPaused());

        $this->disk(4, 75);
        $this->assertTrue($guard->collectionPaused());
        $this->assertStringContainsString('bekletiliyor', (string) $guard->warning());
    }
}
