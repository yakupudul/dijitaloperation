<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * moxdop:db:slim — shrinks the Search Console warehouse, which held ~51 of the 60 GB on staging.
 *
 * 1. Tables of families that are no longer collected (config moxdop-gsc-collector.disabled_families) are
 *    emptied with TRUNCATE (instant; the space goes back to the disk at once) and their coverage records removed.
 *    Search Console keeps 16 months, so the data can be collected again if a family is re-enabled.
 * 2. Rows written before the compact format carry ~11 metadata keys of which only the average position is read.
 *    Each table / monthly partition is rewritten to keep just that key, then rebuilt (VACUUM FULL) so the space
 *    returns to the disk. One partition at a time, only when free disk allows it.
 *
 * Without --execute it only prints the plan.
 */
final class DatabaseSlimCommand extends Command
{
    /** @var list<string> */
    private const ANALYTICS_TABLES = [
        'gsc_property_daily', 'gsc_query_daily', 'gsc_page_daily', 'gsc_query_page_daily', 'gsc_device_daily', 'gsc_country_daily',
        'gsc_search_appearance_daily', 'gsc_search_appearance_page_daily',
        'gsc_page_device_daily', 'gsc_page_country_daily', 'gsc_query_device_daily', 'gsc_query_country_daily',
    ];

    /** @var array<string, string> request family => table */
    private const FAMILY_TABLES = [
        'GSC_RF_PAGE_DEVICE_DAILY' => 'gsc_page_device_daily',
        'GSC_RF_PAGE_COUNTRY_DAILY' => 'gsc_page_country_daily',
        'GSC_RF_QUERY_DEVICE_DAILY' => 'gsc_query_device_daily',
        'GSC_RF_QUERY_COUNTRY_DAILY' => 'gsc_query_country_daily',
    ];

    protected $signature = 'moxdop:db:slim
        {--execute : Uygula (yoksa yalnız plan)}
        {--reserve-gb=1.5 : Diskte her zaman boş kalacak alan}';

    protected $description = 'Search Console tablolarını küçültür: toplanmayan tabloları boşaltır, eski satırların metadata alanını sadeleştirir.';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('Yalnız PostgreSQL için.');

            return self::SUCCESS;
        }
        $execute = (bool) $this->option('execute');
        $reserve = (float) $this->option('reserve-gb') * 1e9;
        $this->line(sprintf('Veritabanı: %s · disk boş: %s', $this->gb($this->databaseBytes()), $this->gb($this->freeBytes())));

        // 1. Families no longer collected.
        $disabled = (array) config('moxdop-gsc-collector.disabled_families', []);
        $truncate = [];
        foreach (self::FAMILY_TABLES as $family => $table) {
            // Only real tables: a converted (compact) table is a view and is already small.
            if (in_array($family, $disabled, true) && $this->isBaseTable($table)) {
                $truncate[$table] = $this->tableBytes($table);
            }
        }
        $this->line('');
        $this->line('1) Artık toplanmayan tablolar boşaltılacak:');
        foreach ($truncate as $table => $bytes) {
            $this->line(sprintf('   %s: %s', $table, $this->gb($bytes)));
        }
        if ($truncate === []) {
            $this->line('   (yok)');
        }

        // 2. Old-format metadata.
        $leaves = $this->oldFormatLeaves(array_diff(self::ANALYTICS_TABLES, array_keys($truncate)));
        $this->line('');
        $this->line('2) Eski biçimli metadata sadeleştirilecek tablo/bölümler: '.count($leaves).' ('.$this->gb(array_sum(array_column($leaves, 'bytes'))).')');

        if (! $execute) {
            $this->line('');
            $this->line('Plan bu. Uygulamak için: php artisan moxdop:db:slim --execute');

            return self::SUCCESS;
        }

        foreach ($truncate as $table => $bytes) {
            DB::statement('TRUNCATE TABLE '.$this->quote($table));
            if ($this->exists('dataset_materializations')) {
                DB::table('dataset_materializations')->where('dataset_id', $table)->delete();
            }
            $this->line(sprintf('✓ boşaltıldı %s (%s)', $table, $this->gb($bytes)));
        }

        foreach ($leaves as $leaf) {
            // UPDATE writes a new version of each row, VACUUM FULL then writes the compact copy.
            if ($this->freeBytes() - $leaf['bytes'] * 1.3 < $reserve) {
                $this->warn(sprintf('Atlandı (disk yetmez): %s (%s) — önce moxdop:db:reclaim --execute çalıştırıp tekrar deneyin.', $leaf['name'], $this->gb($leaf['bytes'])));

                continue;
            }
            $started = microtime(true);
            try {
                $type = $leaf['type'];
                $updated = DB::update(sprintf(
                    "update %s set metadata = json_build_object('provider_average_position', (metadata::jsonb)->'provider_average_position')::%s where (metadata::jsonb) ->> 'provider_ctr_semantic' is not null",
                    $this->quote($leaf['name']), $type === 'jsonb' ? 'jsonb' : 'json',
                ));
                DB::statement('VACUUM (FULL, ANALYZE) '.$this->quote($leaf['name']));
            } catch (Throwable $exception) {
                $this->error($leaf['name'].': '.mb_substr($exception->getMessage(), 0, 200));

                continue;
            }
            $after = $this->tableBytes($leaf['name']);
            $this->line(sprintf('✓ %s: %s satır · %s → %s (%.0f sn)', $leaf['name'], number_format($updated, 0, ',', '.'), $this->gb($leaf['bytes']), $this->gb($after), microtime(true) - $started));
        }
        $this->info(sprintf('Bitti. Veritabanı: %s · disk boş: %s', $this->gb($this->databaseBytes()), $this->gb($this->freeBytes())));

        return self::SUCCESS;
    }

    /**
     * Leaf tables (monthly partitions, or the table itself) of the given parents that still hold old-format rows.
     *
     * @param  array<int, string>  $parents
     * @return list<array{name: string, bytes: int, type: string}>
     */
    private function oldFormatLeaves(array $parents): array
    {
        $out = [];
        foreach ($parents as $parent) {
            if (! $this->exists($parent)) {
                continue;
            }
            $type = (string) (DB::selectOne("select data_type from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = 'metadata'", [$parent])->data_type ?? '');
            if ($type === '') {
                continue;
            }
            $children = DB::select('select c.relname as name from pg_inherits i join pg_class c on c.oid = i.inhrelid join pg_class p on p.oid = i.inhparent where p.relname = ?', [$parent]);
            $names = $children !== [] ? array_map(fn (object $c): string => (string) $c->name, $children) : [$parent];
            foreach ($names as $name) {
                $old = DB::selectOne(sprintf("select 1 as x from %s where (metadata::jsonb) ->> 'provider_ctr_semantic' is not null limit 1", $this->quote($name)));
                if ($old !== null) {
                    $out[] = ['name' => $name, 'bytes' => $this->tableBytes($name), 'type' => $type];
                }
            }
        }
        usort($out, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $out;
    }

    private function isBaseTable(string $table): bool
    {
        $kind = DB::selectOne('select c.relkind as k from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and c.relname = ?', [$table])->k ?? null;

        return in_array($kind, ['r', 'p'], true);
    }

    private function exists(string $table): bool
    {
        return DB::selectOne('select to_regclass(?) as r', [$this->quote($table)])->r !== null;
    }

    private function tableBytes(string $table): int
    {
        // Partitioned parent: sum its partitions.
        $row = DB::selectOne('select coalesce(sum(pg_total_relation_size(c.oid)), 0) as b from pg_class c
            where c.oid = ?::regclass or c.oid in (select inhrelid from pg_inherits where inhparent = ?::regclass)', [$this->quote($table), $this->quote($table)]);

        return (int) $row->b;
    }

    private function databaseBytes(): int
    {
        return (int) DB::selectOne('select pg_database_size(current_database()) as b')->b;
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

    private function quote(string $table): string
    {
        return '"'.str_replace('"', '""', $table).'"';
    }

    private function gb(int|float $bytes): string
    {
        return sprintf('%.2f GB', $bytes / 1e9);
    }
}
