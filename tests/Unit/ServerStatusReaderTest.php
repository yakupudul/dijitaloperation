<?php

namespace Tests\Unit;

use App\Services\Operations\ServerStatusReader;
use PHPUnit\Framework\TestCase;

/** Sunucu kartı: /proc/meminfo turned into total, available and swap bytes; sizes in Turkish GB / MB. */
final class ServerStatusReaderTest extends TestCase
{
    public function test_meminfo_is_read_in_bytes_with_available_memory_as_free(): void
    {
        $meminfo = "MemTotal:        8000000 kB\nMemFree:          500000 kB\nMemAvailable:    3000000 kB\nBuffers:  100000 kB\nCached: 2000000 kB\nSwapTotal:       2000000 kB\nSwapFree:        1500000 kB\n";

        $this->assertSame(['total' => 8192000000, 'available' => 3072000000, 'swap_total' => 2048000000, 'swap_free' => 1536000000], ServerStatusReader::parseMeminfo($meminfo));
        $this->assertSame(2662400000, ServerStatusReader::parseMeminfo("MemTotal: 8000000 kB\nMemFree: 500000 kB\nCached: 2000000 kB\nBuffers: 100000 kB\n")['available'], 'old kernels: free + cache');
        $this->assertSame(0, ServerStatusReader::parseMeminfo('')['total']);
    }

    public function test_sizes_are_written_in_gb_or_mb(): void
    {
        $this->assertSame('12,4 GB', ServerStatusReader::gb(12.4e9));
        $this->assertSame('512 MB', ServerStatusReader::gb(512e6));
    }
}
