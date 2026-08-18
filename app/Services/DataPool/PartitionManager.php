<?php

namespace App\Services\DataPool;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Idempotent PostgreSQL monthly RANGE partition manager.
 * No-op on SQLite / non-pgsql drivers.
 */
final class PartitionManager
{
    public function isPartitioningSupported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Ensure monthly partitions exist covering [from, to] inclusive reporting dates.
     */
    public function ensureRange(string $table, CarbonImmutable|string $from, CarbonImmutable|string $to): void
    {
        if (! $this->isPartitioningSupported()) {
            return;
        }

        $start = CarbonImmutable::parse($from)->startOfMonth();
        $end = CarbonImmutable::parse($to)->startOfMonth();
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $cursor = $start;
        while ($cursor->lte($end)) {
            $this->ensureMonth($table, $cursor);
            $cursor = $cursor->addMonth();
        }
    }

    public function ensureMonth(string $table, CarbonImmutable $month): void
    {
        if (! $this->isPartitioningSupported()) {
            return;
        }

        $month = $month->startOfMonth();
        $partition = sprintf('%s_%s', $table, $month->format('Y_m'));
        $from = $month->format('Y-m-d');
        $to = $month->addMonth()->format('Y-m-d');

        $lockKey = abs(crc32('moxdop_part_'.$partition));

        DB::connection()->transaction(function () use ($lockKey, $partition, $table, $from, $to): void {
            // Session-level advisory lock — race-safe across workers without broad table locks.
            DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

            $exists = DB::selectOne(
                'SELECT 1 AS ok FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()',
                [$partition]
            );

            if ($exists !== null) {
                return;
            }

            try {
                DB::statement(sprintf(
                    'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
                    $this->quoteIdent($partition),
                    $this->quoteIdent($table),
                    DB::getPdo()->quote($from),
                    DB::getPdo()->quote($to),
                ));
            } catch (Throwable $e) {
                // Concurrent create — verify existence, else fail loudly (never drop rows).
                $existsAfter = DB::selectOne(
                    'SELECT 1 AS ok FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()',
                    [$partition]
                );
                if ($existsAfter === null) {
                    throw new RuntimeException(
                        "Failed to ensure partition [{$partition}] for [{$table}]: ".$e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        });
    }

    private function quoteIdent(string $ident): string
    {
        return '"'.str_replace('"', '""', $ident).'"';
    }
}
