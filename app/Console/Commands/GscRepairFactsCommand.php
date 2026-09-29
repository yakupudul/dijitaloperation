<?php

namespace App\Console\Commands;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\SearchConsole\SearchConsoleCentralCollectionService;
use App\Services\DataPool\Compact\CompactFactStore;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\PartitionManager;
use App\Support\Integrations\Google\GoogleResourceType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * moxdop:gsc:repair-facts — Search Console facts missing although runs reported them covered.
 *
 * 1. Makes sure the monthly partitions (and the DEFAULT safety partition) of every compact gsc_f_* fact table cover
 *    the whole Search Console history window plus the coming months.
 * 2. Per bound Search Console property of the asset: shows every Search Analytics dataset's stored rows / latest
 *    date and its last run, and marks datasets with no rows (or whose last run failed) as affected.
 * 3. With --apply: queues a re-fetch of the affected datasets over the window (fresh checkpoints).
 *
 * Dry-run by default.
 */
final class GscRepairFactsCommand extends Command
{
    protected $signature = 'moxdop:gsc:repair-facts
        {--asset= : Dijital varlık id veya adı (web sitesi)}
        {--resource= : Doğrudan Search Console kaynak id (core_external_resources)}
        {--days=486 : Yeniden alınacak pencere (gün)}
        {--dataset=* : Yalnız bu veri setleri (varsayılan: etkilenenler)}
        {--apply : Bölümleri oluştur ve yeniden aktarımı kuyruğa al (yoksa yalnız rapor)}';

    protected $description = 'Search Console olgu tablolarındaki eksik bölümleri onarır ve eksik veri setlerini yeniden aktarır (varsayılan: kuru çalıştırma).';

    public function handle(PartitionManager $partitions, SearchConsoleCentralCollectionService $gsc, DataPoolStorageRegistry $storage): int
    {
        $resources = $this->resources();
        if ($resources->isEmpty()) {
            $this->error('Search Console kaynağı bulunamadı (--asset= veya --resource= verin).');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $days = max(1, (int) $this->option('days'));
        $windowStart = CarbonImmutable::now()->subDays($days + 3)->startOfMonth();

        // 1. Partitions.
        $facts = collect((array) config('moxdop-compact-facts.tables'))->pluck('fact')->map(fn ($fact): string => (string) $fact)->values();
        if (! $partitions->isPartitioningSupported()) {
            $this->line('Bölümler: PostgreSQL değil, atlandı.');
        } else {
            foreach ($facts as $fact) {
                if (! $partitions->isPartitioned($fact)) {
                    continue;
                }
                $months = count($partitions->monthsInRange($windowStart, CarbonImmutable::now()->addMonths(3)));
                if ($apply) {
                    $partitions->ensureRange($fact, $windowStart, CarbonImmutable::now()->addMonths(3));
                    $partitions->ensureDefault($fact);
                    $this->line(sprintf('Bölümler hazır: %s (%d ay + DEFAULT)', $fact, $months));
                } else {
                    $this->line(sprintf('Bölümler hazırlanacak: %s (%s → +3 ay, %d ay + DEFAULT)', $fact, $windowStart->format('Y-m'), $months));
                }
            }
            if ($apply) {
                CompactFactStore::forgetCache();
            }
        }

        // 2. Datasets per resource.
        $only = array_values(array_filter((array) $this->option('dataset'), fn ($d): bool => is_string($d) && $d !== ''));
        $exit = self::SUCCESS;
        foreach ($resources as $resource) {
            $this->info(sprintf('Kaynak #%d %s', $resource->id, $resource->display_name ?: $resource->external_id));
            $affected = [];
            foreach (SearchConsoleRequestFamilyCatalog::centralPerformanceFamilies() as $family) {
                $datasetId = (string) (SearchConsoleRequestFamilyCatalog::definition($family)['dataset_id'] ?? '');
                if ($datasetId === '' || ! $storage->hasPhysicalTable($datasetId) || ($only !== [] && ! in_array($datasetId, $only, true))) {
                    continue;
                }
                [$rows, $latest] = $this->facts($storage->tableName($datasetId), (int) $resource->id, $windowStart);
                $lastRun = DB::table('collection_dataset_runs as d')
                    ->join('collection_resource_runs as r', 'r.id', '=', 'd.collection_resource_run_id')
                    ->where('r.external_resource_id', $resource->id)->where('d.provider_or_source', 'SEARCH_CONSOLE')
                    ->where('d.dataset_contract_id', $datasetId)->orderByDesc('d.id')->first(['d.status', 'd.error_message']);
                $failed = $lastRun !== null && in_array((string) $lastRun->status, ['failed', 'partial', 'retrying'], true);
                $isAffected = $only !== [] || $rows === 0 || $failed;
                if ($isAffected && $datasetId !== 'gsc_property_daily') {
                    $affected[] = $datasetId;
                }
                $this->line(sprintf('  %s %s: %d satır · son tarih %s · son çalışma %s%s',
                    $isAffected ? '✗' : '✓', $datasetId, $rows, $latest ?? 'yok', $lastRun->status ?? '—',
                    filled($lastRun->error_message ?? null) ? ' · '.mb_substr((string) $lastRun->error_message, 0, 140) : ''));
            }
            $affected = array_values(array_unique($affected));
            if ($affected === []) {
                $this->line('  Eksik veri seti yok.');

                continue;
            }
            if (! $apply) {
                $this->line(sprintf('  Yeniden aktarılacak (%d gün): %s', $days, implode(', ', $affected)));

                continue;
            }
            try {
                $integration = CoreIntegration::query()->findOrFail($resource->integration_id);
                $run = $gsc->startRefetch($integration, [(int) $resource->id], $affected, $days);
                $this->info(sprintf('  Yeniden aktarım kuyrukta: çalışma #%d (%s)', $run->id, implode(', ', $affected)));
            } catch (Throwable $e) {
                $this->error('  Kuyruğa alınamadı: '.mb_substr($e->getMessage(), 0, 300));
                $exit = self::FAILURE;
            }
        }
        if (! $apply) {
            $this->line('Kuru çalıştırma. Uygulamak için --apply ekleyin.');
        }

        return $exit;
    }

    /** @return Collection<int, CoreExternalResource> */
    private function resources(): Collection
    {
        $query = CoreExternalResource::query()->where('resource_type', GoogleResourceType::GSC_PROPERTY);
        if (filled($this->option('resource'))) {
            return $query->whereKey((int) $this->option('resource'))->get();
        }
        $asset = trim((string) $this->option('asset'));
        if ($asset === '') {
            return collect();
        }
        $assetIds = ctype_digit($asset)
            ? [(int) $asset]
            : DB::table('digital_assets')->whereNull('deleted_at')->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($asset).'%'])->limit(5)->pluck('id')->all();

        return $query->whereIn('id', DB::table('core_asset_bindings')->whereIn('digital_asset_id', $assetIds ?: [0])
            ->where('status', 'active')->select('external_resource_id'))->orderBy('id')->get();
    }

    /** @return array{0: int, 1: ?string} rows in the window and the latest reporting date */
    private function facts(string $table, int $resourceId, CarbonImmutable $from): array
    {
        try {
            $row = DB::table($table)->where('external_resource_id', $resourceId)->where('reporting_date', '>=', $from->toDateString())
                ->selectRaw('count(*) as n, max(reporting_date) as latest')->first();
        } catch (Throwable) {
            return [0, null];
        }

        return [(int) ($row->n ?? 0), $row?->latest !== null ? substr((string) $row->latest, 0, 10) : null];
    }
}
