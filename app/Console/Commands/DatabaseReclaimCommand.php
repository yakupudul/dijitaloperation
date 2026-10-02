<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * moxdop:db:reclaim — gives disk space held by old row versions back to the operating system.
 *
 * PostgreSQL never shrinks a table file on its own: rows rewritten by re-collections leave space that is only
 * reused, not returned. This compares each table / partition's file size with the size its live rows need
 * (row count × average row width from the planner statistics) and rebuilds the bloated ones with
 * VACUUM (FULL, ANALYZE). A rebuild needs free disk about the size of the live data of that one table, so work
 * goes partition by partition, biggest win first, and stops before free space gets tight. Without --execute it
 * only prints the plan. A rebuild locks that one table while it runs (seconds to minutes).
 */
final class DatabaseReclaimCommand extends Command
{
    protected $signature = 'moxdop:db:reclaim
        {--execute : Tabloları gerçekten yeniden yaz (yoksa yalnız plan)}
        {--min-mb=50 : Bundan küçük kazançları atla}
        {--reserve-gb=1.5 : Diskte her zaman boş kalacak alan}
        {--max-tables=200 : Bir çalıştırmada en fazla bu kadar tablo}';

    protected $description = 'Şişmiş PostgreSQL tablolarını (ölü satır alanı) diske geri verir; varsayılan yalnız plan.';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('Yalnız PostgreSQL için.');

            return self::SUCCESS;
        }
        $execute = (bool) $this->option('execute');
        $minBytes = max(1, (int) $this->option('min-mb')) * 1_000_000;
        $reserve = (float) $this->option('reserve-gb') * 1e9;

        // Fresh planner statistics so row counts and widths are current (cheap, sampled).
        if ($execute) {
            DB::statement('ANALYZE');
        }
        $plan = collect($this->candidates())
            ->filter(fn (array $t): bool => $t['reclaimable'] >= $minBytes)
            ->sortByDesc('reclaimable')->take(max(1, (int) $this->option('max-tables')))->values();

        $this->line(sprintf('Veritabanı: %.2f GB · disk boş: %.2f GB', $this->databaseBytes() / 1e9, $this->freeBytes() / 1e9));
        if ($plan->isEmpty()) {
            $this->info('Geri kazanılacak kayda değer şişkinlik yok.');

            return self::SUCCESS;
        }
        $this->table(['Tablo', 'Dosya', 'Canlı veri (tahmin)', 'Kazanç (tahmin)'], $plan->map(fn (array $t): array => [
            $t['name'], $this->gb($t['total']), $this->gb($t['needed']), $this->gb($t['reclaimable']),
        ])->all());
        $this->line(sprintf('Toplam tahmini kazanç: %s', $this->gb((int) $plan->sum('reclaimable'))));

        if (! $execute) {
            $this->line('Plan bu. Uygulamak için: php artisan moxdop:db:reclaim --execute');

            return self::SUCCESS;
        }

        $reclaimed = 0;
        foreach ($plan as $t) {
            $free = $this->freeBytes();
            // VACUUM FULL writes a new copy of the live rows and indexes before dropping the old file.
            if ($free - $t['needed'] * 1.3 < $reserve) {
                $this->warn(sprintf('Atlandı (disk yetmez): %s — gereken ~%s, boş %s', $t['name'], $this->gb((int) ($t['needed'] * 1.3)), $this->gb((int) $free)));

                continue;
            }
            $before = $this->relationBytes($t['name']);
            $started = microtime(true);
            try {
                DB::statement('VACUUM (FULL, ANALYZE) '.$this->quote($t['name']));
            } catch (Throwable $exception) {
                $this->error($t['name'].': '.mb_substr($exception->getMessage(), 0, 200));

                continue;
            }
            $after = $this->relationBytes($t['name']);
            $reclaimed += max(0, $before - $after);
            $this->line(sprintf('✓ %s: %s → %s (%.0f sn)', $t['name'], $this->gb($before), $this->gb($after), microtime(true) - $started));
        }
        $this->info(sprintf('Geri kazanılan: %s · disk boş: %s', $this->gb($reclaimed), $this->gb((int) $this->freeBytes())));

        return self::SUCCESS;
    }

    /**
     * Leaf tables (each partition on its own) with their file size and the size their live rows need.
     *
     * @return list<array{name: string, total: int, needed: int, reclaimable: int}>
     */
    private function candidates(): array
    {
        $rows = DB::select("select c.relname as name, pg_total_relation_size(c.oid) as total, pg_relation_size(c.oid) as heap,
                greatest(coalesce(s.n_live_tup, 0), c.reltuples::bigint, 0) as live,
                coalesce((select sum(st.avg_width) from pg_stats st where st.schemaname = n.nspname and st.tablename = c.relname), 0) as width
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            left join pg_stat_user_tables s on s.relid = c.oid
            where n.nspname = current_schema() and c.relkind = 'r' and pg_total_relation_size(c.oid) > 10000000");

        $out = [];
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $heap = (int) $row->heap;
            if ((int) $row->width === 0) {
                continue; // no statistics yet: cannot estimate safely
            }
            // Live heap ≈ rows × (width + tuple header + line pointer) with ~15 % page overhead; indexes are
            // rebuilt too, so scale the non-heap part by the same live share.
            $liveHeap = (int) ((int) $row->live * ((int) $row->width + 28) * 1.15);
            $share = $heap > 0 ? min(1.0, $liveHeap / $heap) : 1.0;
            $needed = (int) ($liveHeap + ($total - $heap) * max($share, 0.5));
            $out[] = ['name' => (string) $row->name, 'total' => $total, 'needed' => $needed, 'reclaimable' => max(0, $total - $needed)];
        }

        return $out;
    }

    private function relationBytes(string $table): int
    {
        return (int) DB::selectOne('select pg_total_relation_size(?::regclass) as b', [$this->quote($table)])->b;
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
