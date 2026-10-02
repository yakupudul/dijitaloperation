<?php

namespace App\Services\DataPool;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Idempotent PostgreSQL monthly RANGE partition manager.
 * No-op on SQLite / non-pgsql drivers.
 *
 * Every partitioned parent also gets a DEFAULT partition (`{table}_default`) as a safety net: a row whose month has
 * no partition yet lands there instead of failing the whole batch with "no partition of relation … found for row".
 * When the month partition is created later, rows already in the default partition for that month are moved into it.
 */
class PartitionManager
{
    public const string DEFAULT_SUFFIX = '_default';

    /** @var array<string, array{0: bool, 1: float}> table => [is partitioned, checked at] */
    private static array $partitionedCache = [];

    public function isPartitioningSupported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** Whether the relation is a partitioned (RANGE) parent table. Always false off PostgreSQL. */
    public function isPartitioned(string $table): bool
    {
        if (! $this->isPartitioningSupported()) {
            return false;
        }
        [$partitioned, $checked] = self::$partitionedCache[$table] ?? [false, 0.0];
        if (microtime(true) - $checked > 60) {
            $partitioned = DB::selectOne("SELECT 1 AS ok FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relname = ? AND n.nspname = current_schema() AND c.relkind = 'p'", [$table]) !== null;
            self::$partitionedCache[$table] = [$partitioned, microtime(true)];
        }

        return $partitioned;
    }

    public static function forgetCache(): void
    {
        self::$partitionedCache = [];
    }

    /**
     * Month starts covering [from, to] inclusive reporting dates (order-insensitive).
     *
     * @return list<CarbonImmutable>
     */
    public function monthsInRange(CarbonImmutable|string $from, CarbonImmutable|string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfMonth();
        $end = CarbonImmutable::parse($to)->startOfMonth();
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }
        $months = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addMonth()) {
            $months[] = $cursor;
        }

        return $months;
    }

    public function partitionName(string $table, CarbonImmutable $month): string
    {
        return sprintf('%s_%s', $table, $month->startOfMonth()->format('Y_m'));
    }

    /**
     * Ensure monthly partitions exist covering [from, to] inclusive reporting dates.
     */
    public function ensureRange(string $table, CarbonImmutable|string $from, CarbonImmutable|string $to): void
    {
        if (! $this->isPartitioningSupported()) {
            return;
        }

        foreach ($this->monthsInRange($from, $to) as $month) {
            $this->ensureMonth($table, $month);
        }
    }

    /**
     * Before a write: when the target is a partitioned parent (declared RANGE_MONTHLY, or a compact fact table, which
     * is always partitioned even when its logical dataset is declared NONE), make sure every month the rows touch has
     * its partition. Returns whether partitions were ensured.
     */
    public function ensureForWrite(string $table, bool $declaredPartitioned, CarbonImmutable|string $from, CarbonImmutable|string $to): bool
    {
        if (! $declaredPartitioned && ! $this->isPartitioned($table)) {
            return false;
        }
        $this->ensureRange($table, $from, $to);

        return true;
    }

    public function ensureMonth(string $table, CarbonImmutable $month): void
    {
        if (! $this->isPartitioningSupported()) {
            return;
        }

        $month = $month->startOfMonth();
        $partition = $this->partitionName($table, $month);
        $from = $month->format('Y-m-d');
        $to = $month->addMonth()->format('Y-m-d');

        $lockKey = abs(crc32('moxdop_part_'.$partition));

        DB::connection()->transaction(function () use ($lockKey, $partition, $table, $from, $to): void {
            // Transaction-level advisory lock — race-safe across workers without broad table locks.
            DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

            if ($this->relationExists($partition)) {
                return;
            }

            try {
                $default = $table.self::DEFAULT_SUFFIX;
                $column = $this->partitionColumn($table);
                $defaultHasRows = $this->isAttachedDefault($table, $default) && DB::selectOne(sprintf(
                    'SELECT 1 AS ok FROM %s WHERE %s >= ? AND %s < ? LIMIT 1',
                    $this->quoteIdent($default), $this->quoteIdent($column), $this->quoteIdent($column)
                ), [$from, $to]) !== null;

                foreach ($this->createMonthStatements($table, $partition, $from, $to, $column, $defaultHasRows) as $sql) {
                    DB::statement($sql);
                }
            } catch (Throwable $e) {
                // Concurrent create — verify existence, else fail loudly (never drop rows).
                if (! $this->relationExists($partition)) {
                    throw new RuntimeException(
                        "Failed to ensure partition [{$partition}] for [{$table}]: ".$e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        });
    }

    /**
     * SQL that creates one month partition. When the DEFAULT partition already holds rows of that month (they landed
     * there before the partition existed), PostgreSQL refuses the CREATE; the default is detached, the month created,
     * its rows moved over and the default re-attached — all inside the caller's transaction.
     *
     * @return list<string>
     */
    public function createMonthStatements(string $table, string $partition, string $from, string $to, string $column = 'reporting_date', bool $defaultHasRows = false): array
    {
        $create = sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
            $this->quoteIdent($partition), $this->quoteIdent($table), $this->quoteLiteral($from), $this->quoteLiteral($to),
        );
        if (! $defaultHasRows) {
            return [$create];
        }
        $default = $this->quoteIdent($table.self::DEFAULT_SUFFIX);
        $range = sprintf('%s >= %s AND %s < %s', $this->quoteIdent($column), $this->quoteLiteral($from), $this->quoteIdent($column), $this->quoteLiteral($to));

        return [
            sprintf('ALTER TABLE %s DETACH PARTITION %s', $this->quoteIdent($table), $default),
            $create,
            sprintf('INSERT INTO %s OVERRIDING SYSTEM VALUE SELECT * FROM %s WHERE %s', $this->quoteIdent($table), $default, $range),
            sprintf('DELETE FROM %s WHERE %s', $default, $range),
            sprintf('ALTER TABLE %s ATTACH PARTITION %s DEFAULT', $this->quoteIdent($table), $default),
        ];
    }

    /** SQL of the DEFAULT (catch-all) partition of a parent. */
    public function createDefaultStatement(string $table): string
    {
        return sprintf('CREATE TABLE IF NOT EXISTS %s PARTITION OF %s DEFAULT', $this->quoteIdent($table.self::DEFAULT_SUFFIX), $this->quoteIdent($table));
    }

    /** Creates the DEFAULT partition of a partitioned parent (idempotent). Returns whether it was created now. */
    public function ensureDefault(string $table): bool
    {
        if (! $this->isPartitioningSupported() || ! $this->isPartitioned($table)) {
            return false;
        }
        $default = $table.self::DEFAULT_SUFFIX;
        if ($this->relationExists($default)) {
            return false;
        }
        try {
            DB::statement($this->createDefaultStatement($table));
        } catch (Throwable $e) {
            if (! $this->relationExists($default)) {
                throw new RuntimeException("Failed to ensure default partition for [{$table}]: ".$e->getMessage(), 0, $e);
            }

            return false;
        }

        return true;
    }

    /**
     * Partitioned parents of the current schema (top level only).
     *
     * @return list<string>
     */
    public function partitionedTables(): array
    {
        if (! $this->isPartitioningSupported()) {
            return [];
        }

        return array_map(fn (object $row): string => (string) $row->relname, DB::select(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relkind = 'p' AND NOT c.relispartition ORDER BY c.relname"
        ));
    }

    /**
     * Keeps every partitioned parent writable: a DEFAULT partition plus the month partitions from `monthsBack`
     * months ago through `monthsAhead` months ahead.
     *
     * @param  list<string>|null  $tables  null = every partitioned parent
     * @return array{tables: int, defaults_created: int}
     */
    public function ensureAhead(int $monthsAhead = 3, int $monthsBack = 0, ?CarbonImmutable $now = null, ?array $tables = null): array
    {
        $now ??= CarbonImmutable::now();
        $defaults = 0;
        $tables ??= $this->partitionedTables();
        foreach ($tables as $table) {
            if (! $this->isPartitioned($table)) {
                continue;
            }
            $this->ensureRange($table, $now->subMonths(max(0, $monthsBack)), $now->addMonths(max(0, $monthsAhead)));
            $defaults += $this->ensureDefault($table) ? 1 : 0;
        }

        return ['tables' => count($tables), 'defaults_created' => $defaults];
    }

    private function relationExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()',
            [$name]
        ) !== null;
    }

    private function isAttachedDefault(string $table, string $default): bool
    {
        return DB::selectOne(
            "SELECT 1 AS ok FROM pg_inherits i JOIN pg_class p ON p.oid = i.inhparent JOIN pg_class c ON c.oid = i.inhrelid
             JOIN pg_namespace n ON n.oid = p.relnamespace
             WHERE p.relname = ? AND c.relname = ? AND n.nspname = current_schema() AND pg_get_expr(c.relpartbound, c.oid) = 'DEFAULT'",
            [$table, $default]
        ) !== null;
    }

    /** The RANGE partition key column (reporting_date for every data pool table). */
    private function partitionColumn(string $table): string
    {
        $row = DB::selectOne(
            'SELECT pg_get_partkeydef(c.oid) AS def FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()',
            [$table]
        );
        if ($row !== null && preg_match('/RANGE\s*\(\s*"?([A-Za-z0-9_]+)"?\s*\)/i', (string) $row->def, $m) === 1) {
            return $m[1];
        }

        return 'reporting_date';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function quoteIdent(string $ident): string
    {
        return '"'.str_replace('"', '""', $ident).'"';
    }
}
