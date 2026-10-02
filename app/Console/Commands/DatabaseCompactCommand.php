<?php

namespace App\Console\Commands;

use App\Services\DataPool\Compact\CompactFactStore;
use App\Services\DataPool\PartitionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * moxdop:db:compact — converts high-cardinality fact tables to compact storage (config moxdop-compact-facts).
 *
 * Per logical table: copy every row into the compact fact table (text → dictionary ids), partition by partition;
 * then, holding a short exclusive lock, copy rows written meanwhile, rename the old table and create a view with
 * the old name and columns. Row count and click totals are compared before the old table is dropped; on a
 * mismatch it is kept (as <name>__legacy) and reported. Readers keep working the whole time; a collection write
 * that hits the swap moment fails once and is retried into the compact table.
 */
final class DatabaseCompactCommand extends Command
{
    protected $signature = 'moxdop:db:compact
        {--execute : Dönüştür (yoksa yalnız plan)}
        {--table= : Yalnız bu mantıksal tablo}
        {--keep-legacy : Eski tabloyu silme (<ad>__legacy olarak kalır)}
        {--reserve-gb=1.5 : Diskte her zaman boş kalacak alan}';

    protected $description = 'Yüksek hacimli veri tablolarını sözlük kimlikli sıkı depolamaya çevirir (okuyan kod değişmez).';

    public function handle(CompactFactStore $store, PartitionManager $partitions): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('Yalnız PostgreSQL için.');

            return self::SUCCESS;
        }
        $tables = [...array_keys((array) config('moxdop-compact-facts.tables')), ...array_keys((array) config('moxdop-compact-facts.generic'))];
        if (filled($this->option('table'))) {
            $tables = array_values(array_intersect($tables, [(string) $this->option('table')]));
        }
        $todo = [];
        foreach ($tables as $logical) {
            $kind = DB::selectOne('select c.relkind as k from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and c.relname = ?', [$logical])->k ?? null;
            if (in_array($kind, ['r', 'p'], true)) {
                $todo[$logical] = $this->bytes($logical);
            } else {
                $this->line(sprintf('· %s: %s', $logical, $kind === 'v' ? 'zaten sıkı' : 'tablo yok'));
            }
        }
        asort($todo);
        foreach ($todo as $logical => $bytes) {
            $this->line(sprintf('→ %s: %s (tahmini yeni boyut ~%s)', $logical, $this->gb($bytes), $this->gb($bytes / ($store->spec($logical)['generic'] ? 3 : 9))));
        }
        if (! $this->option('execute') || $todo === []) {
            if ($todo !== []) {
                $this->line('Plan bu. Uygulamak için: php artisan moxdop:db:compact --execute');
            }

            return self::SUCCESS;
        }

        foreach ($todo as $logical => $bytes) {
            if ($this->freeBytes() - $bytes * 0.25 < (float) $this->option('reserve-gb') * 1e9) {
                $this->warn(sprintf('Atlandı (disk yetmez): %s', $logical));

                continue;
            }
            try {
                $this->convert($store, $partitions, $logical);
            } catch (Throwable $exception) {
                $this->error($logical.': '.mb_substr($exception->getMessage(), 0, 300));
            }
        }
        $this->info(sprintf('Bitti. Veritabanı: %s · disk boş: %s', $this->gb((int) DB::selectOne('select pg_database_size(current_database()) as b')->b), $this->gb($this->freeBytes())));

        return self::SUCCESS;
    }

    private function convert(CompactFactStore $store, PartitionManager $partitions, string $logical): void
    {
        $spec = $store->spec($logical);
        if ($spec['generic']) {
            $this->convertGeneric($store, $partitions, $logical, $spec['fact']);

            return;
        }
        $started = microtime(true);
        $since = (string) DB::selectOne('select now()::text as t')->t;
        $leaves = array_map(fn (object $r): string => (string) $r->name, DB::select(
            'select c.relname as name from pg_inherits i join pg_class c on c.oid = i.inhrelid where i.inhparent = ?::regclass order by 1', [$this->q($logical)]));
        if ($leaves === []) {
            $leaves = [$logical];
        }
        foreach ($leaves as $leaf) {
            $range = DB::selectOne(sprintf('select min(reporting_date)::text as a, max(reporting_date)::text as b from %s', $this->q($leaf)));
            if ($range->a === null) {
                continue;
            }
            $partitions->ensureRange($spec['fact'], $range->a, $range->b);
            $this->copy($spec, $leaf, null, false);
            $this->line(sprintf('   %s kopyalandı', $leaf));
        }

        DB::transaction(function () use ($store, $partitions, $spec, $logical, $since): void {
            DB::statement('LOCK TABLE '.$this->q($logical).' IN ACCESS EXCLUSIVE MODE');
            // Rows written while the copy ran.
            $range = DB::selectOne(sprintf('select min(reporting_date)::text as a, max(reporting_date)::text as b from %s where last_collected_at >= ?', $this->q($logical)), [$since]);
            if ($range->a !== null) {
                $partitions->ensureRange($spec['fact'], $range->a, $range->b);
                $this->copy($spec, $logical, $since, true);
            }
            DB::statement(sprintf('ALTER TABLE %s RENAME TO %s', $this->q($logical), $this->q($logical.'__legacy')));
            DB::statement($store->viewSql($logical));
        });
        CompactFactStore::forgetCache();

        $old = DB::selectOne(sprintf('select count(*) as n, coalesce(sum(clicks), 0) as c from %s', $this->q($logical.'__legacy')));
        $new = DB::selectOne(sprintf('select count(*) as n, coalesce(sum(clicks), 0) as c from %s', $this->q($logical)));
        $same = (int) $old->n === (int) $new->n && (int) $old->c === (int) $new->c;
        $this->line(sprintf('   doğrulama: eski %s satır / %s tık · yeni %s satır / %s tık', number_format((int) $old->n, 0, ',', '.'), number_format((int) $old->c, 0, ',', '.'),
            number_format((int) $new->n, 0, ',', '.'), number_format((int) $new->c, 0, ',', '.')));
        if (! $same || $this->option('keep-legacy')) {
            $this->warn(sprintf('   %s__legacy silinmedi%s. Kontrol sonrası: DROP TABLE "%s__legacy" CASCADE;', $logical, $same ? '' : ' (sayılar farklı)', $logical));
        } else {
            DB::statement(sprintf('DROP TABLE %s CASCADE', $this->q($logical.'__legacy')));
        }
        $this->info(sprintf('✓ %s sıkı depolamada: %s (%.0f sn)', $logical, $this->gb($this->bytes($spec['fact'])), microtime(true) - $started));
    }

    /** GA4 / Meta / Google Ads tables: layout from the live table, same copy → swap → verify steps. */
    private function convertGeneric(CompactFactStore $store, PartitionManager $partitions, string $logical, string $fact): void
    {
        $generic = $store->generic();
        $started = microtime(true);
        $since = (string) DB::selectOne('select now()::text as t')->t;
        $layout = $generic->prepare($logical, $fact);
        $leaves = array_map(fn (object $r): string => (string) $r->name, DB::select(
            'select c.relname as name from pg_inherits i join pg_class c on c.oid = i.inhrelid where i.inhparent = ?::regclass order by 1', [$this->q($logical)]));
        foreach ($leaves === [] ? [$logical] : $leaves as $leaf) {
            $generic->copy($layout, $leaf, null, false, $partitions);
            $this->line(sprintf('   %s kopyalandı', $leaf));
        }

        DB::transaction(function () use ($store, $generic, $partitions, $layout, $logical, $since): void {
            DB::statement('LOCK TABLE '.$this->q($logical).' IN ACCESS EXCLUSIVE MODE');
            // Rows written while the copy ran.
            $generic->copy($layout, $logical, $since, true, $partitions);
            DB::statement(sprintf('ALTER TABLE %s RENAME TO %s', $this->q($logical), $this->q($logical.'__legacy')));
            DB::statement($store->viewSql($logical));
        });
        $generic->syncSequence($layout);
        CompactFactStore::forgetCache();

        $sum = $layout['check'] !== null ? 'coalesce(sum('.$this->q($layout['check']).'), 0)' : '0';
        $old = DB::selectOne(sprintf('select count(*) as n, %s as c from %s', $sum, $this->q($logical.'__legacy')));
        $new = DB::selectOne(sprintf('select count(*) as n, %s as c from %s', $sum, $this->q($logical)));
        $same = (int) $old->n === (int) $new->n && (string) $old->c === (string) $new->c;
        $this->line(sprintf('   doğrulama: eski %s satır / %s=%s · yeni %s satır / %s', number_format((int) $old->n, 0, ',', '.'), $layout['check'] ?? '-', $old->c,
            number_format((int) $new->n, 0, ',', '.'), $new->c));
        if (! $same || $this->option('keep-legacy')) {
            $this->warn(sprintf('   %s__legacy silinmedi%s. Kontrol sonrası: DROP TABLE "%s__legacy" CASCADE;', $logical, $same ? '' : ' (sayılar farklı)', $logical));
        } else {
            DB::statement(sprintf('DROP TABLE %s CASCADE', $this->q($logical.'__legacy')));
        }
        $this->info(sprintf('✓ %s sıkı depolamada: %s (%.0f sn)', $logical, $this->gb($this->bytes($fact)), microtime(true) - $started));
    }

    /**
     * Copies rows of a legacy table (or one partition) into the compact fact table.
     *
     * @param  array{fact: string, dims: list<string>}  $spec
     */
    private function copy(array $spec, string $source, ?string $since, bool $overwrite): void
    {
        $kinds = (array) config('moxdop-compact-facts.kinds');
        $where = $since !== null ? ' where last_collected_at >= '.DB::getPdo()->quote($since) : '';
        $texts = ['site_url' => $kinds['site'], 'search_type' => $kinds['search_type']];
        foreach ($spec['dims'] as $dim) {
            $texts[$dim] = $kinds[$dim];
        }
        foreach ($texts as $column => $kind) {
            DB::statement(sprintf('INSERT INTO fact_dims (kind, value, value_hash) SELECT DISTINCT %d, v, md5(v)::uuid FROM (SELECT %s::text AS v FROM %s%s) x
                WHERE v IS NOT NULL ON CONFLICT (kind, value_hash) DO NOTHING', $kind, $this->q($column), $this->q($source), $where));
        }
        $dimCols = '';
        $dimSelect = '';
        $dimJoin = '';
        foreach ($spec['dims'] as $i => $dim) {
            $n = $i + 1;
            $dimCols .= ", d{$n}";
            $dimSelect .= ", x{$n}.id";
            $dimJoin .= sprintf(' JOIN fact_dims x%d ON x%d.kind = %d AND x%d.value_hash = md5(l.%s::text)::uuid', $n, $n, $kinds[$dim], $n, $this->q($dim));
        }
        $key = 'resource_id, reporting_date, site_id, search_type_id'.$dimCols;
        $conflict = $overwrite
            ? 'DO UPDATE SET clicks = EXCLUDED.clicks, impressions = EXCLUDED.impressions, position = EXCLUDED.position, run_id = EXCLUDED.run_id, collected_at = EXCLUDED.collected_at'
            : 'DO NOTHING';
        DB::statement(sprintf('INSERT INTO %s (%s, clicks, impressions, position, digital_asset_id, run_id, collected_at)
            SELECT coalesce(l.external_resource_id, 0), l.reporting_date, s.id, t.id%s,
                least(greatest(l.clicks, 0), 2147483647)::int, least(greatest(l.impressions, 0), 2147483647)::int,
                ((l.metadata::jsonb) ->> \'provider_average_position\')::real, l.digital_asset_id::int, l.last_dataset_run_id::int,
                coalesce(l.last_collected_at, now())
            FROM %s l
            JOIN fact_dims s ON s.kind = %d AND s.value_hash = md5(l.site_url::text)::uuid
            JOIN fact_dims t ON t.kind = %d AND t.value_hash = md5(coalesce(l.search_type, \'web\')::text)::uuid%s%s
            ON CONFLICT (%s) %s',
            $this->q($spec['fact']), $key, $dimSelect, $this->q($source), $kinds['site'], $kinds['search_type'], $dimJoin,
            $since !== null ? ' WHERE l.last_collected_at >= '.DB::getPdo()->quote($since) : '', $key, $conflict));
    }

    private function bytes(string $table): int
    {
        return (int) DB::selectOne('select coalesce(sum(pg_total_relation_size(c.oid)), 0) as b from pg_class c
            where c.oid = ?::regclass or c.oid in (select inhrelid from pg_inherits where inhparent = ?::regclass)', [$this->q($table), $this->q($table)])->b;
    }

    private function freeBytes(): float
    {
        try {
            $dir = (string) DB::selectOne("select current_setting('data_directory') as d")->d;
        } catch (Throwable) {
            $dir = '/';
        }

        return (float) (@disk_free_space(is_dir($dir) ? $dir : '/') ?: @disk_free_space('/') ?: 0);
    }

    private function q(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function gb(int|float $bytes): string
    {
        return sprintf('%.2f GB', $bytes / 1e9);
    }
}
