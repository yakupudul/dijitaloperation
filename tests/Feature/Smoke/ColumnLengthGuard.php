<?php

namespace Tests\Feature\Smoke;

/**
 * SQLite ignores varchar lengths; PostgreSQL rejects a longer value ("value too long for type character varying(n)")
 * and the request fails. Reads the string/char column sizes from the migrations and reports inserts/updates whose
 * bound text is longer than the column, so SQLite test runs catch what production would refuse.
 */
final class ColumnLengthGuard
{
    /** @var array<string, array<string, int>>|null table → column → max length */
    private static ?array $limits = null;

    /** @return array<string, array<string, int>> */
    public static function limits(): array
    {
        if (self::$limits !== null) {
            return self::$limits;
        }
        $limits = [];
        $files = glob(dirname(__DIR__, 3).'/database/migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // Each Schema::create/table block: the table name, then the column definitions up to the closing "});".
            preg_match_all("/Schema::(?:create|table)\\(\\s*'([a-z0-9_]+)'\\s*,\\s*function.*?\\n\\s*\\}\\);/s", $source, $blocks, PREG_SET_ORDER);
            foreach ($blocks as [$block, $table]) {
                preg_match_all("/->(string|char)\\(\\s*'([a-z0-9_]+)'\\s*(?:,\\s*(\\d+))?\\s*\\)/", $block, $columns, PREG_SET_ORDER);
                foreach ($columns as $column) {
                    $limits[$table][$column[2]] = isset($column[3]) && $column[3] !== '' ? (int) $column[3] : 255;
                }
                // A column turned into text/json later is unlimited.
                preg_match_all("/->(?:text|longText|mediumText|json|jsonb)\\(\\s*'([a-z0-9_]+)'/", $block, $wide);
                foreach ($wide[1] as $column) {
                    unset($limits[$table][$column]);
                }
            }
        }

        return self::$limits = $limits;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string> "table.column (len > max)"
     */
    public static function violations(string $sql, array $bindings): array
    {
        $columns = [];
        if (preg_match('/^\s*insert\s+(?:or\s+\w+\s+)?into\s+"([a-z0-9_]+)"\s*\(([^)]*)\)\s*values\s*/i', $sql, $m) === 1) {
            $table = $m[1];
            $names = array_map(fn (string $c): string => trim($c, ' "`'), explode(',', $m[2]));
            // Multi-row inserts repeat the column list.
            foreach (array_values($bindings) as $index => $value) {
                $columns[] = [$names[$index % max(1, count($names))] ?? '', $value];
            }
        } elseif (preg_match('/^\s*update\s+"([a-z0-9_]+)"\s+set\s+(.*?)\s+where\s/is', $sql, $m) === 1) {
            $table = $m[1];
            preg_match_all('/"([a-z0-9_]+)"\s*=\s*\?/', $m[2], $sets);
            foreach ($sets[1] as $index => $name) {
                $columns[] = [$name, $bindings[$index] ?? null];
            }
        } else {
            return [];
        }
        $limits = self::limits()[$table] ?? [];
        $out = [];
        foreach ($columns as [$name, $value]) {
            if (is_string($value) && isset($limits[$name]) && mb_strlen($value) > $limits[$name]) {
                $out[] = sprintf('%s.%s (%d > %d)', $table, $name, mb_strlen($value), $limits[$name]);
            }
        }

        return $out;
    }
}
