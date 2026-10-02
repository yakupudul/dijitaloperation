<?php

namespace App\Console\Commands;

use App\Services\Collection\Website\WebsiteCollectionStopper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * moxdop:website:reset-collection — one-time clean-up after the switch to latest-state website storage.
 *
 * 1. Cancels every active website collection run through the normal cancellation path (queued work is cancelled at
 *    once, a step that is executing stops at its next safe boundary), so the site is free for a new collection.
 * 2. Deletes all website_link_edge rows (they are rebuilt, one set per page, by the next crawl).
 * 3. Keeps only the latest row per page (per page + issue code for crawl issues) in the per-page snapshot tables,
 *    plus the stored HTML copies (raw objects) only the deleted HTML rows used.
 *
 * Without --force it only prints what it would do.
 */
final class WebsiteResetCollectionCommand extends Command
{
    /** Dataset providers of a website collection run. */
    public const WEBSITE_PROVIDERS = WebsiteCollectionStopper::WEBSITE_PROVIDERS;

    /** table => columns that identify one page state (besides digital_asset_id). */
    private const SNAPSHOT_TABLES = [
        'website_http_snapshot' => ['url'],
        'website_html_snapshot' => ['url'],
        'website_metadata_snapshot' => ['url'],
        'website_heading_snapshot' => ['url'],
        'website_schema_snapshot' => ['url'],
        'website_content_stats' => ['url'],
        'website_crawl_issue_snapshot' => ['url', 'issue_code'],
    ];

    protected $signature = 'moxdop:website:reset-collection
        {--force : Gerçekten uygula (yoksa yalnız ne yapılacağını yazar)}
        {--chunk=20000 : Bir silme adımındaki kimlik aralığı}';

    protected $description = 'Aktif web sitesi çekimlerini iptal eder, bağlantı kenarlarını siler ve sayfa tablolarında yalnız son kaydı bırakır.';

    public function handle(WebsiteCollectionStopper $stopper): int
    {
        $force = (bool) $this->option('force');
        $chunk = max(1000, (int) $this->option('chunk'));
        $this->info($force ? 'Web sitesi veri çekimi sıfırlanıyor.' : 'Deneme: hiçbir şey değişmez; uygulamak için --force verin.');

        $this->cancelActiveRuns($force, $stopper);
        $this->purgeLinkEdges($force);
        foreach (self::SNAPSHOT_TABLES as $table => $keyColumns) {
            $this->keepLatestRows($table, $keyColumns, $chunk, $force);
        }

        if ($force) {
            $this->info('Bitti. PostgreSQL disk alanını geri vermek için: php artisan moxdop:db:reclaim --execute');
        }

        return self::SUCCESS;
    }

    private function cancelActiveRuns(bool $force, WebsiteCollectionStopper $stopper): void
    {
        $runs = $stopper->activeRuns();
        $this->line(sprintf('Aktif web sitesi çekimi: %d%s', $runs->count(), $runs->isEmpty() ? '' : ' (#'.$runs->pluck('id')->implode(', #').')'));
        if (! $force || $runs->isEmpty()) {
            return;
        }

        $result = $stopper->stop($runs);
        $this->line(sprintf('  iptal edildi: %d çekim%s', $result['stopped'], $result['waiting'] > 0 ? sprintf(' (%d adım şu an çalışıyor; bitince durur)', $result['waiting']) : ''));
    }

    private function purgeLinkEdges(bool $force): void
    {
        if (! Schema::hasTable('website_link_edge')) {
            return;
        }
        $count = DB::table('website_link_edge')->count();
        $this->line(sprintf('website_link_edge: %s satır silinecek', number_format($count, 0, ',', '.')));
        if ($force && $count > 0) {
            DB::table('website_link_edge')->truncate();
            $this->line('  silindi.');
        }
    }

    /**
     * Deletes every row that has a newer row for the same page (newer observed_at, or the same observed_at and a
     * higher id), id range by id range. Portable SQL (PostgreSQL and SQLite); the correlated lookup uses the
     * (digital_asset_id, url, …, observed_at) unique index of each table.
     *
     * @param  list<string>  $keyColumns
     */
    private function keepLatestRows(string $table, array $keyColumns, int $chunk, bool $force): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $newer = function ($query) use ($table, $keyColumns): void {
            $query->selectRaw('1')->from($table.' as newer')
                ->whereColumn('newer.digital_asset_id', $table.'.digital_asset_id');
            foreach ($keyColumns as $column) {
                $query->whereColumn('newer.'.$column, $table.'.'.$column);
            }
            $query->where(fn ($later) => $later->whereColumn('newer.observed_at', '>', $table.'.observed_at')
                ->orWhere(fn ($same) => $same->whereColumn('newer.observed_at', $table.'.observed_at')
                    ->whereColumn('newer.id', '>', $table.'.id')));
        };

        $total = DB::table($table)->count();
        if (! $force) {
            $stale = DB::table($table)->whereExists($newer)->count();
            $this->line(sprintf('%s: %s satırdan %s eski kopya silinecek', $table, number_format($total, 0, ',', '.'), number_format($stale, 0, ',', '.')));

            return;
        }

        $minId = (int) DB::table($table)->min('id');
        $maxId = (int) DB::table($table)->max('id');
        $deleted = 0;
        $rawDeleted = 0;
        for ($from = $minId; $total > 0 && $from <= $maxId; $from += $chunk) {
            $range = fn () => DB::table($table)->whereBetween('id', [$from, $from + $chunk - 1])->whereExists($newer);
            $rawIds = $table === 'website_html_snapshot'
                ? $range()->whereNotNull('raw_ingestion_object_id')->distinct()->pluck('raw_ingestion_object_id')->map(fn ($id): int => (int) $id)->all()
                : [];
            $deleted += $range()->delete();
            if ($rawIds !== []) {
                $rawDeleted += $this->deleteUnreferencedRawObjects($rawIds);
            }
        }

        $this->line(sprintf('%s: %s satırdan %s eski kopya silindi%s', $table, number_format($total, 0, ',', '.'), number_format($deleted, 0, ',', '.'),
            $table === 'website_html_snapshot' ? sprintf(' (%s saklanan HTML dosyası)', number_format($rawDeleted, 0, ',', '.')) : ''));
    }

    /**
     * Stored HTML copies of the deleted rows that no remaining HTML row uses. Other references
     * (dataset_write_batches) are nulled by their foreign key.
     *
     * @param  list<int>  $rawIds
     */
    private function deleteUnreferencedRawObjects(array $rawIds): int
    {
        if (! Schema::hasTable('raw_ingestion_objects')) {
            return 0;
        }
        $deleted = 0;
        foreach (array_chunk($rawIds, 500) as $ids) {
            $objects = DB::table('raw_ingestion_objects as o')->whereIn('o.id', $ids)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('website_html_snapshot as s')->whereColumn('s.raw_ingestion_object_id', 'o.id'))
                ->get(['o.id', 'o.storage_disk', 'o.object_key']);
            foreach ($objects as $object) {
                try {
                    Storage::disk((string) $object->storage_disk)->delete((string) $object->object_key);
                } catch (Throwable $error) {
                    report($error);

                    continue;
                }
                $deleted += DB::table('raw_ingestion_objects')->where('id', $object->id)->delete();
            }
        }

        return $deleted;
    }
}
