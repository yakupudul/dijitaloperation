<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Audit D7 (2026-10-22): migrations run in file-name order on a fresh database and on the server. Files already run on
 * the server are never renamed (the migrations table knows them by name), so the rule holds for new files: a new
 * migration is named after the newest one, never with today's date between older ones, and never shares a timestamp.
 */
final class MigrationOrderTest extends TestCase
{
    /** Timestamps shared before the rule; their order is fixed by name and the server already ran them. */
    private const array SHARED_BEFORE_RULE = ['2026_09_24_090000', '2026_10_17_090000', '2026_10_18_090000', '2026_11_03_090000', '2026_11_06_090000', '2026_11_07_090000'];

    public function test_every_migration_has_its_own_timestamp_and_a_plain_name(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: []);
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/', $file);
        }
        $counts = array_count_values(array_map(fn (string $f): string => substr($f, 0, 17), $files));
        $shared = array_keys(array_filter($counts, fn (int $n): bool => $n > 1));
        $this->assertSame([], array_values(array_diff($shared, self::SHARED_BEFORE_RULE)), 'a new migration shares a timestamp; name it after the newest file');
    }
}
