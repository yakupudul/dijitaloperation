<?php

namespace App\Services\DataPool\Compact;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compact fact storage on PostgreSQL (config moxdop-compact-facts).
 *
 * Rows of a converted logical table (e.g. gsc_query_page_daily) live in a narrow fact table: text dimensions are
 * integer ids into the shared fact_dims dictionary, metrics are int4, position is real, and only one primary key
 * is kept. The logical name is a VIEW returning the old columns, so readers do not change. Writes go through
 * upsert(); unchanged rows are not rewritten.
 */
final class CompactFactStore
{
    /** @var array<string, array{0: bool, 1: float}> table => [is compact, checked at] */
    private static array $compactCache = [];

    /** @var array<string, int> "kind|value" => id, per process */
    private static array $dimCache = [];

    /** @return array{fact: string, dims: list<string>}|null */
    public function spec(string $logical): ?array
    {
        $spec = config('moxdop-compact-facts.tables.'.$logical);

        return is_array($spec) ? ['fact' => (string) $spec['fact'], 'dims' => array_values((array) $spec['dims'])] : null;
    }

    /** Whether writes to this logical table go to its compact fact table (it has been converted to a view). */
    public function isCompact(string $logical): bool
    {
        if (DB::getDriverName() !== 'pgsql' || $this->spec($logical) === null) {
            return false;
        }
        [$compact, $checked] = self::$compactCache[$logical] ?? [false, 0.0];
        // Long-running workers re-check so a conversion done by the command is picked up.
        if (microtime(true) - $checked > 30) {
            $compact = DB::selectOne("select 1 as x from pg_class c join pg_namespace n on n.oid = c.relnamespace
                where n.nspname = current_schema() and c.relname = ? and c.relkind = 'v'", [$logical]) !== null;
            self::$compactCache[$logical] = [$compact, microtime(true)];
        }

        return $compact;
    }

    public static function forgetCache(): void
    {
        self::$compactCache = [];
        self::$dimCache = [];
    }

    /**
     * Upserts logical rows (the old column shape) into the compact fact table.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    public function upsert(string $logical, array $rows): array
    {
        $spec = $this->spec($logical) ?? throw new RuntimeException("No compact spec for [{$logical}]");
        if ($rows === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }
        $kinds = (array) config('moxdop-compact-facts.kinds');

        // Resolve every distinct text once.
        $wanted = [];
        foreach ($rows as $row) {
            $wanted[$kinds['site']][(string) $row['site_url']] = true;
            $wanted[$kinds['search_type']][(string) ($row['search_type'] ?? 'web')] = true;
            foreach ($spec['dims'] as $dim) {
                $wanted[$kinds[$dim]][(string) $row[$dim]] = true;
            }
        }
        $ids = $this->dimIds($wanted);

        $columns = ['resource_id', 'reporting_date', 'site_id', 'search_type_id'];
        foreach (array_keys($spec['dims']) as $i) {
            $columns[] = 'd'.($i + 1);
        }
        array_push($columns, 'clicks', 'impressions', 'position', 'digital_asset_id', 'run_id', 'collected_at');

        $values = [];
        foreach ($rows as $row) {
            $metadata = is_string($row['metadata'] ?? null) ? json_decode((string) $row['metadata'], true) : ($row['metadata'] ?? null);
            $position = is_array($metadata) && is_numeric($metadata['provider_average_position'] ?? null) ? (float) $metadata['provider_average_position'] : null;
            $record = [
                (int) ($row['external_resource_id'] ?? 0),
                (string) $row['reporting_date'],
                $ids[$kinds['site'].'|'.(string) $row['site_url']],
                $ids[$kinds['search_type'].'|'.(string) ($row['search_type'] ?? 'web')],
            ];
            foreach ($spec['dims'] as $dim) {
                $record[] = $ids[$kinds[$dim].'|'.(string) $row[$dim]];
            }
            array_push($record, (int) ($row['clicks'] ?? 0), (int) ($row['impressions'] ?? 0), $position,
                isset($row['digital_asset_id']) ? (int) $row['digital_asset_id'] : null,
                isset($row['last_dataset_run_id']) ? (int) $row['last_dataset_run_id'] : null,
                (string) ($row['last_collected_at'] ?? now()));
            // Last one wins when a batch repeats a key.
            $values[implode('|', array_slice($record, 0, 4 + count($spec['dims'])))] = $record;
        }

        $key = array_slice($columns, 0, 4 + count($spec['dims']));
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (array_chunk(array_values($values), 1000) as $chunk) {
            $bindings = [];
            $placeholders = [];
            foreach ($chunk as $record) {
                $placeholders[] = '('.implode(', ', array_fill(0, count($record), '?')).')';
                array_push($bindings, ...$record);
            }
            $fact = '"'.$spec['fact'].'"';
            // Keys already present (primary-key lookups) tell inserts from updates.
            $keyPlaceholders = [];
            $keyBindings = [];
            foreach ($chunk as $record) {
                $keyPlaceholders[] = '('.implode(', ', array_fill(0, count($key), '?')).')';
                array_push($keyBindings, ...array_slice($record, 0, count($key)));
            }
            $keyCasts = implode(' AND ', array_map(fn (string $c): string => "f.{$c} = v.{$c}".($c === 'reporting_date' ? '::date' : '::int'), $key));
            $existing = (int) DB::selectOne(sprintf('SELECT count(*) AS n FROM %s f JOIN (VALUES %s) AS v(%s) ON %s',
                $fact, implode(', ', $keyPlaceholders), implode(', ', $key), $keyCasts), $keyBindings)->n;
            // Rows whose values did not change are not rewritten (and not returned).
            $written = count(DB::select(sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET clicks = EXCLUDED.clicks, impressions = EXCLUDED.impressions,
                    position = EXCLUDED.position, digital_asset_id = COALESCE(EXCLUDED.digital_asset_id, %s.digital_asset_id),
                    run_id = EXCLUDED.run_id, collected_at = EXCLUDED.collected_at
                 WHERE (%s.clicks, %s.impressions, %s.position) IS DISTINCT FROM (EXCLUDED.clicks, EXCLUDED.impressions, EXCLUDED.position)
                 RETURNING 1 AS w',
                $fact, implode(', ', $columns), implode(', ', $placeholders), implode(', ', $key), $fact, $fact, $fact, $fact,
            ), $bindings));
            $inserted = count($chunk) - $existing;
            $stats['inserted'] += $inserted;
            $stats['updated'] += max(0, $written - $inserted);
            $stats['unchanged'] += count($chunk) - $written;
        }

        return $stats;
    }

    /**
     * Dictionary ids for texts, inserting unknown ones.
     *
     * @param  array<int, array<string, true>>  $wanted  kind => [value => true]
     * @return array<string, int> "kind|value" => id
     */
    public function dimIds(array $wanted): array
    {
        $out = [];
        $fresh = [];
        $missing = [];
        foreach ($wanted as $kind => $values) {
            foreach (array_keys($values) as $value) {
                $value = (string) $value;
                $cacheKey = $kind.'|'.$value;
                if (isset(self::$dimCache[$cacheKey])) {
                    $out[$cacheKey] = self::$dimCache[$cacheKey];
                } else {
                    $missing[] = [(int) $kind, $value];
                }
            }
        }
        foreach (array_chunk($missing, 1000) as $chunk) {
            $bindings = [];
            $placeholders = [];
            foreach ($chunk as [$kind, $value]) {
                $placeholders[] = '(?::smallint, ?::text)';
                array_push($bindings, $kind, $value);
            }
            $sql = 'WITH input(kind, value) AS (VALUES '.implode(', ', $placeholders).'),
                ins AS (INSERT INTO fact_dims (kind, value, value_hash) SELECT kind, value, md5(value)::uuid FROM input
                    ON CONFLICT (kind, value_hash) DO NOTHING RETURNING id, kind, value)
                SELECT id, kind, value FROM ins
                UNION ALL
                SELECT d.id, d.kind, d.value FROM fact_dims d JOIN input i ON d.kind = i.kind AND d.value_hash = md5(i.value)::uuid';
            foreach (DB::select($sql, $bindings) as $row) {
                $cacheKey = $row->kind.'|'.$row->value;
                $out[$cacheKey] = $fresh[$cacheKey] = (int) $row->id;
            }
        }
        // A value inserted by a concurrent transaction is invisible to the statement above; read it again.
        $stillMissing = array_values(array_filter($missing, fn (array $m): bool => ! isset($out[$m[0].'|'.$m[1]])));
        foreach (array_chunk($stillMissing, 1000) as $chunk) {
            $bindings = [];
            $placeholders = [];
            foreach ($chunk as [$kind, $value]) {
                $placeholders[] = '(?::smallint, ?::text)';
                array_push($bindings, $kind, $value);
            }
            foreach (DB::select('SELECT d.id, d.kind, d.value FROM fact_dims d JOIN (VALUES '.implode(', ', $placeholders).') AS i(kind, value)
                ON d.kind = i.kind AND d.value_hash = md5(i.value)::uuid', $bindings) as $row) {
                $cacheKey = $row->kind.'|'.$row->value;
                $out[$cacheKey] = $fresh[$cacheKey] = (int) $row->id;
            }
        }
        // Ids created inside a transaction that later rolls back must never reach the process cache: a cached id
        // that does not exist would make rows vanish from the view. Promote them only after commit.
        if ($fresh !== []) {
            DB::afterCommit(static function () use ($fresh): void {
                if (count(self::$dimCache) > 200000) {
                    self::$dimCache = [];
                }
                self::$dimCache += $fresh;
            });
        }

        return $out;
    }

    /** SQL of the view that gives the compact table the logical table's columns. */
    public function viewSql(string $logical): string
    {
        $spec = $this->spec($logical) ?? throw new RuntimeException("No compact spec for [{$logical}]");
        $timezone = DB::getPdo()->quote((string) config('moxdop-compact-facts.source_timezone', 'America/Los_Angeles'));
        $dimSelect = '';
        $dimJoin = '';
        foreach ($spec['dims'] as $i => $dim) {
            $n = $i + 1;
            $dimSelect .= sprintf(', x%d.value AS "%s"', $n, $dim);
            $dimJoin .= sprintf(' JOIN fact_dims x%d ON x%d.id = f.d%d', $n, $n, $n);
        }

        return sprintf('CREATE VIEW "%s" AS SELECT NULL::bigint AS id, f.digital_asset_id, f.resource_id AS external_resource_id, s.value AS site_url,
                f.reporting_date%s, f.clicks, f.impressions, 1 AS contract_version, NULL::bigint AS last_collection_run_id,
                f.run_id AS last_dataset_run_id, f.collected_at AS first_collected_at, f.collected_at AS last_collected_at,
                %s::text AS source_timezone, NULL::char(64) AS record_fingerprint,
                jsonb_build_object(\'provider_average_position\', f.position) AS metadata, f.collected_at AS created_at,
                f.collected_at AS updated_at, t.value::varchar(32) AS search_type
            FROM "%s" f JOIN fact_dims s ON s.id = f.site_id JOIN fact_dims t ON t.id = f.search_type_id%s',
            $logical, $dimSelect, $timezone, $spec['fact'], $dimJoin);
    }

    /**
     * Converts a logical table that holds no rows: drop it and create the view (fresh installs, emptied tables).
     * Tables with rows are converted by moxdop:db:compact, which copies and verifies first.
     */
    public function convertIfEmpty(string $logical): bool
    {
        if (DB::getDriverName() !== 'pgsql' || $this->spec($logical) === null) {
            return false;
        }
        $kind = DB::selectOne('select c.relkind as k from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and c.relname = ?', [$logical])->k ?? null;
        if (! in_array($kind, ['r', 'p'], true) || DB::selectOne(sprintf('select 1 as x from "%s" limit 1', $logical)) !== null) {
            return false;
        }
        DB::transaction(function () use ($logical): void {
            DB::statement(sprintf('DROP TABLE "%s" CASCADE', $logical));
            DB::statement($this->viewSql($logical));
        });
        self::forgetCache();

        return true;
    }
}
