<?php

namespace App\Services\DataPool\Compact;

use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\PartitionManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compact storage for any daily fact table (GA4, Meta, Google Ads), PostgreSQL only.
 *
 * The layout is derived from the live table once, when it is converted, and kept in compact_fact_layouts:
 * - every text / json column becomes an int4 id into the shared fact_dims dictionary (kind GENERIC_KIND);
 * - record_fingerprint is dropped, created_at / updated_at / first_collected_at are read from last_collected_at;
 * - the other columns keep their type; nullable key columns store 0 for NULL (the view gives NULL back);
 * - only the contract natural key (primary key) and an asset/date index are kept.
 * The logical name becomes a VIEW with the old columns, so readers do not change.
 */
final class GenericCompactStore
{
    public const int GENERIC_KIND = 20;

    /** Columns that are not stored; the view derives them. */
    private const array DERIVED = [
        'record_fingerprint' => 'NULL::char(64)',
        'created_at' => 'f.last_collected_at',
        'updated_at' => 'f.last_collected_at',
        'first_collected_at' => 'f.last_collected_at',
    ];

    /** Columns refreshed with a changed row but not compared to decide whether it changed. */
    private const array PROVENANCE = ['last_collected_at', 'last_dataset_run_id', 'last_collection_run_id', 'contract_version'];

    /** @var array<string, array<string, mixed>|null> */
    private static array $layouts = [];

    public function __construct(private readonly CompactFactStore $dictionary) {}

    public static function forgetCache(): void
    {
        self::$layouts = [];
    }

    /**
     * @return array{fact: string, key: list<string>, partitioned: bool, check: ?string, columns: list<array{name: string, type: string, store: string, key: bool, nullable: bool}>}|null
     */
    public function layout(string $logical): ?array
    {
        if (! array_key_exists($logical, self::$layouts)) {
            $row = DB::table('compact_fact_layouts')->where('logical', $logical)->first();
            self::$layouts[$logical] = $row === null ? null : json_decode((string) $row->layout, true);
        }

        return self::$layouts[$logical];
    }

    /**
     * Reads the live table and the contract natural key; creates the fact table and stores the layout.
     *
     * @return array<string, mixed>
     */
    public function prepare(string $logical, string $fact): array
    {
        $key = $this->naturalKey($logical);
        $relation = DB::selectOne("select c.oid, c.relkind from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = current_schema() and c.relname = ? and c.relkind in ('r', 'p')", [$logical])
            ?? throw new RuntimeException("[{$logical}] is not a table");
        $columns = [];
        $check = null;
        foreach (DB::select('select a.attname as name, format_type(a.atttypid, a.atttypmod) as type, t.typcategory as category, not a.attnotnull as nullable
            from pg_attribute a join pg_type t on t.oid = a.atttypid where a.attrelid = ? and a.attnum > 0 and not a.attisdropped order by a.attnum', [$relation->oid]) as $column) {
            $name = (string) $column->name;
            $isKey = in_array($name, $key, true);
            $store = match (true) {
                array_key_exists($name, self::DERIVED) => 'derived',
                $name === 'id' => 'id',
                in_array($column->category, ['S'], true) || in_array($column->type, ['json', 'jsonb'], true) => 'dict',
                default => 'native',
            };
            if ($check === null && $store === 'native' && ! $isKey && $column->category === 'N' && $name !== 'contract_version' && ! str_ends_with($name, '_id')) {
                $check = $name;
            }
            $columns[] = ['name' => $name, 'type' => (string) $column->type, 'store' => $store, 'key' => $isKey, 'nullable' => (bool) $column->nullable];
        }
        $names = array_column($columns, 'name');
        foreach ([...$key, 'last_collected_at'] as $required) {
            if (! in_array($required, $names, true)) {
                throw new RuntimeException("[{$logical}] has no [{$required}] column");
            }
        }

        $partitioned = $relation->relkind === 'p';
        $definitions = [];
        foreach ($columns as $column) {
            $definitions[] = match ($column['store']) {
                'derived' => null,
                'id' => 'id bigint NOT NULL DEFAULT nextval('.DB::getPdo()->quote($fact.'_id_seq').')',
                'dict' => $this->q($column['name']).' integer'.($column['key'] ? ' NOT NULL' : ''),
                default => $this->q($column['name']).' '.$column['type'].($column['key'] || ! $column['nullable'] ? ' NOT NULL' : ''),
            };
        }
        DB::statement(sprintf('CREATE SEQUENCE IF NOT EXISTS %s', $this->q($fact.'_id_seq')));
        DB::statement(sprintf('CREATE TABLE IF NOT EXISTS %s (%s, PRIMARY KEY (%s))%s', $this->q($fact),
            implode(', ', array_filter($definitions)), implode(', ', array_map($this->q(...), $key)),
            $partitioned ? ' PARTITION BY RANGE (reporting_date)' : ''));
        if (in_array('digital_asset_id', $names, true) && in_array('reporting_date', $names, true)) {
            DB::statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (digital_asset_id, reporting_date)', $this->q($fact.'_asset_idx'), $this->q($fact)));
        }
        // Resource automation pages rows by (resource, run, id).
        $cursorIndexed = DB::selectOne("select 1 as x from pg_indexes where schemaname = current_schema() and tablename = ? and indexdef like '%last_dataset_run_id%'", [$logical]) !== null;
        if ($cursorIndexed) {
            DB::statement(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (external_resource_id, last_dataset_run_id, id)', $this->q($fact.'_cursor_idx'), $this->q($fact)));
        }

        $layout = ['fact' => $fact, 'key' => $key, 'partitioned' => $partitioned, 'check' => $check, 'columns' => $columns];
        DB::table('compact_fact_layouts')->updateOrInsert(['logical' => $logical], ['layout' => json_encode($layout), 'updated_at' => now(), 'created_at' => now()]);
        self::$layouts[$logical] = $layout;

        return $layout;
    }

    /** SQL of the view that gives the fact table the logical table's columns (in their old order). */
    public function viewSql(string $logical): string
    {
        $layout = $this->layout($logical) ?? throw new RuntimeException("No compact layout for [{$logical}]");
        $select = [];
        $joins = '';
        foreach ($layout['columns'] as $i => $column) {
            $name = $this->q($column['name']);
            $select[] = match ($column['store']) {
                'derived' => self::DERIVED[$column['name']].'::'.$column['type'].' AS '.$name,
                'dict' => sprintf('x%d.value::%s AS %s', $i, $column['type'], $name),
                default => $column['key'] && $column['nullable']
                    ? sprintf('NULLIF(f.%s, 0)::%s AS %s', $name, $column['type'], $name)
                    : 'f.'.$name,
            };
            if ($column['store'] === 'dict') {
                $joins .= sprintf(' %s JOIN fact_dims x%d ON x%d.id = f.%s', $column['key'] ? '' : 'LEFT', $i, $i, $name);
            }
        }

        return sprintf('CREATE VIEW %s AS SELECT %s FROM %s f%s', $this->q($logical), implode(', ', $select), $this->q($layout['fact']), $joins);
    }

    /**
     * Upserts logical rows into the fact table. Rows whose values did not change are left as they are.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    public function upsert(string $logical, array $rows): array
    {
        $layout = $this->layout($logical) ?? throw new RuntimeException("No compact layout for [{$logical}]");
        if ($rows === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }
        $stored = array_values(array_filter($layout['columns'], static fn (array $c): bool => in_array($c['store'], ['dict', 'native'], true)));

        $wanted = [];
        foreach ($rows as $row) {
            foreach ($stored as $column) {
                if ($column['store'] === 'dict' && ($value = $this->text($row[$column['name']] ?? null)) !== null) {
                    $wanted[self::GENERIC_KIND][$value] = true;
                }
            }
        }
        $ids = $wanted === [] ? [] : $this->dictionary->dimIds($wanted);

        $records = [];
        foreach ($rows as $row) {
            $record = [];
            foreach ($stored as $column) {
                $value = $row[$column['name']] ?? null;
                if ($column['store'] === 'dict') {
                    $text = $this->text($value);
                    $value = $text === null ? null : $ids[self::GENERIC_KIND.'|'.$text];
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:sP');
                }
                if ($column['key'] && $value === null) {
                    $value = 0;
                }
                $record[$column['name']] = $value;
            }
            $records[implode('|', array_map(static fn (string $k): string => (string) $record[$k], $layout['key']))] = $record;
        }

        $names = array_column($stored, 'name');
        $types = array_column($stored, 'type', 'name');
        $update = array_values(array_diff($names, $layout['key']));
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (array_chunk(array_values($records), 500) as $chunk) {
            $keyRows = [];
            $keyBindings = [];
            foreach ($chunk as $record) {
                $keyRows[] = '('.implode(', ', array_map(fn (string $k): string => '?::'.($this->isDict($layout, $k) ? 'integer' : $types[$k]), $layout['key'])).')';
                foreach ($layout['key'] as $k) {
                    $keyBindings[] = $record[$k];
                }
            }
            $match = implode(' AND ', array_map(fn (string $k): string => 'f.'.$this->q($k).' = v.'.$this->q($k), $layout['key']));
            $existing = (int) DB::selectOne(sprintf('SELECT count(*) AS n FROM %s f JOIN (VALUES %s) AS v(%s) ON %s', $this->q($layout['fact']),
                implode(', ', $keyRows), implode(', ', array_map($this->q(...), $layout['key'])), $match), $keyBindings)->n;

            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $record) {
                $placeholders[] = '('.implode(', ', array_fill(0, count($names), '?')).')';
                array_push($bindings, ...array_values($record));
            }
            // Unchanged rows are not rewritten (an UPDATE writes a new row version on PostgreSQL).
            $fact = $this->q($layout['fact']);
            $compared = array_values(array_diff($update, self::PROVENANCE));
            $changed = $compared === [] ? '' : sprintf(' WHERE (%s) IS DISTINCT FROM (%s)',
                implode(', ', array_map(fn (string $c): string => $fact.'.'.$this->q($c), $compared)),
                implode(', ', array_map(fn (string $c): string => 'EXCLUDED.'.$this->q($c), $compared)));
            $written = count(DB::select(sprintf('INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO %s RETURNING 1 AS w', $fact,
                implode(', ', array_map($this->q(...), $names)), implode(', ', $placeholders), implode(', ', array_map($this->q(...), $layout['key'])),
                $update === [] ? 'NOTHING' : 'UPDATE SET '.implode(', ', array_map(fn (string $c): string => $this->q($c).' = EXCLUDED.'.$this->q($c), $update)).$changed), $bindings));
            $inserted = count($chunk) - $existing;
            $stats['inserted'] += $inserted;
            $stats['updated'] += max(0, $written - $inserted);
            $stats['unchanged'] += count($chunk) - $written;
        }

        return $stats;
    }

    /**
     * Copies rows of the legacy table (or one partition) into the fact table.
     *
     * @param  array<string, mixed>  $layout
     */
    public function copy(array $layout, string $source, ?string $since, bool $overwrite, ?PartitionManager $partitions = null): void
    {
        $where = $since !== null ? ' WHERE l.last_collected_at >= '.DB::getPdo()->quote($since) : '';
        if ($layout['partitioned'] && $partitions !== null) {
            $range = DB::selectOne(sprintf('select min(reporting_date)::text as a, max(reporting_date)::text as b from %s l%s', $this->q($source), $where));
            if ($range->a === null) {
                return;
            }
            $partitions->ensureRange($layout['fact'], $range->a, $range->b);
        }
        $columns = [];
        $select = [];
        $joins = '';
        $keyFilter = [];
        foreach ($layout['columns'] as $i => $column) {
            $name = $this->q($column['name']);
            if ($column['store'] === 'dict') {
                DB::statement(sprintf('INSERT INTO fact_dims (kind, value, value_hash) SELECT DISTINCT %d, v, md5(v)::uuid FROM (SELECT l.%s::text AS v FROM %s l%s) x
                    WHERE v IS NOT NULL ON CONFLICT (kind, value_hash) DO NOTHING', self::GENERIC_KIND, $name, $this->q($source), $where));
                $joins .= sprintf(' LEFT JOIN fact_dims x%d ON x%d.kind = %d AND x%d.value_hash = md5(l.%s::text)::uuid', $i, $i, self::GENERIC_KIND, $i, $name);
                $columns[] = $name;
                $select[] = "x{$i}.id";
                if ($column['key']) {
                    $keyFilter[] = "l.{$name} IS NOT NULL";
                }
            } elseif ($column['store'] === 'native' || $column['store'] === 'id') {
                $columns[] = $name;
                $select[] = $column['key'] && $column['nullable'] ? "coalesce(l.{$name}, 0)" : "l.{$name}";
            }
        }
        if ($keyFilter !== []) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ').implode(' AND ', $keyFilter);
        }
        $update = array_values(array_diff($columns, array_map($this->q(...), $layout['key']), ['"id"']));
        DB::statement(sprintf('INSERT INTO %s (%s) SELECT %s FROM %s l%s%s ON CONFLICT (%s) DO %s', $this->q($layout['fact']), implode(', ', $columns),
            implode(', ', $select), $this->q($source), $joins, $where, implode(', ', array_map($this->q(...), $layout['key'])),
            $overwrite && $update !== [] ? 'UPDATE SET '.implode(', ', array_map(static fn (string $c): string => "{$c} = EXCLUDED.{$c}", $update)) : 'NOTHING'));
    }

    /** New rows continue after the highest copied id. */
    public function syncSequence(array $layout): void
    {
        if (in_array('id', array_column($layout['columns'], 'name'), true)) {
            DB::statement(sprintf("SELECT setval('%s', greatest((SELECT coalesce(max(id), 0) FROM %s), 1))", str_replace("'", "''", $this->q($layout['fact'].'_id_seq')), $this->q($layout['fact'])));
        }
    }

    /** @return list<string> */
    private function naturalKey(string $logical): array
    {
        foreach (app(DataPoolStorageRegistry::class)->physicalDatasets() as $physical) {
            if (($physical['table'] ?? null) === $logical && str_starts_with((string) ($physical['write_mode'] ?? ''), 'UPSERT')) {
                return array_values((array) $physical['natural_key']);
            }
        }

        throw new RuntimeException("[{$logical}] has no upsert contract natural key");
    }

    /** @param array<string, mixed> $layout */
    private function isDict(array $layout, string $name): bool
    {
        foreach ($layout['columns'] as $column) {
            if ($column['name'] === $name) {
                return $column['store'] === 'dict';
            }
        }

        return false;
    }

    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    private function q(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
