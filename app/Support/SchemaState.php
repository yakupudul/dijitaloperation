<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Schema::hasTable / hasColumn for application code (not migrations). Each Schema call is a catalogue query on
 * PostgreSQL and pages made dozens of them (304 guards, 2026-10-21). A table or column once seen stays known: per
 * process and for an hour in the cache. A missing one is asked again every time, so a migration that adds it is seen
 * at once. Tests ask the database directly (they create and drop tables).
 */
final class SchemaState
{
    /** @var array<string, true> */
    private static array $known = [];

    public static function hasTable(string $table): bool
    {
        return self::remember('t:'.$table, fn (): bool => Schema::hasTable($table));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return self::remember('c:'.$table.'.'.$column, fn (): bool => Schema::hasColumn($table, $column));
    }

    public static function forget(): void
    {
        self::$known = [];
    }

    /** @param callable(): bool $ask */
    private static function remember(string $key, callable $ask): bool
    {
        if (app()->runningUnitTests()) {
            return $ask();
        }
        if (isset(self::$known[$key])) {
            return true;
        }
        $cacheKey = 'schema-state:'.config('database.default').':'.$key;
        try {
            if (Cache::get($cacheKey) === true) {
                return self::$known[$key] = true;
            }
        } catch (\Throwable) {
            // The cache being down never stops a page; the database is asked.
        }
        if (! $ask()) {
            return false;
        }
        self::$known[$key] = true;
        try {
            Cache::put($cacheKey, true, 3600);
        } catch (\Throwable) {
        }

        return true;
    }
}
