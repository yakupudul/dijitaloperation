<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DataPool\DatasetWriteBatch;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\PageSpeedConnectionProbeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Web Sitesi Veri Kaynakları')]
final class WebsiteIntegrationIndex extends Component
{
    public string $message = '';

    public string $messageTone = 'info';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $filter = 'all';

    #[Url(as: 'site', history: true)]
    public ?int $selectedAssetId = null;

    #[Url(as: 'tab', history: true)]
    public string $activeTab = 'overview';

    #[Url(as: 'dataset', history: true)]
    public ?string $selectedDatasetId = null;

    #[Url(as: 'data_q', history: true)]
    public string $dataSearch = '';

    #[Url(as: 'data_page', history: true)]
    public int $dataPage = 1;

    public function mount(?int $assetId = null): void
    {
        if ($assetId !== null) {
            $this->selectedAssetId = $assetId;
        }
    }

    public function setTab(string $tab): void
    {
        $allowed = ['overview', 'sources', 'runs', 'data', 'settings'];
        $this->activeTab = in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    public function selectDataset(string $datasetId): void
    {
        $this->selectedDatasetId = $datasetId;
        $this->activeTab = 'data';
        $this->dataPage = 1;
    }

    public function setDataPage(int $page): void
    {
        $this->dataPage = max(1, $page);
    }

    public function updatedDataSearch(): void
    {
        $this->dataPage = 1;
    }

    public function collectNow(int $assetId, WebsiteCollectionOrchestrator $orchestrator): void
    {
        $asset = DigitalAsset::query()
            ->where('type', 'website')
            ->findOrFail($assetId);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $this->selectedAssetId = $assetId;

        try {
            $run = $orchestrator->start(
                asset: $asset,
                requestedBy: $actor,
                context: [
                    'trigger' => 'operator.integrations.website.collect',
                    'force_refresh' => true,
                ],
            );

            $this->messageTone = 'success';
            $this->message = $this->text(
                "{$asset->name} için veri çekimi kuyruğa alındı. Çekim #{$run->id}.",
                "Website collection queued for {$asset->name}. Run #{$run->id}.",
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->messageTone = 'error';
            $this->message = $this->text('Web sitesi veri çekimi başlatılamadı.', 'Website collection could not be started.');
        }
    }

    public function render(): View
    {
        /** @var DataPoolStorageRegistry $storageRegistry */
        $storageRegistry = app(DataPoolStorageRegistry::class);

        $assets = DigitalAsset::query()
            ->with(['brand.customer', 'connections.credential'])
            ->where('type', 'website')
            ->orderBy('name')
            ->get();

        $runs = $assets->isEmpty()
            ? collect()
            : CollectionRun::query()
                ->with('datasetRuns')
                ->whereIn('digital_asset_id', $assets->pluck('id'))
                ->latest('id')
                ->get()
                ->filter(fn (CollectionRun $run): bool => $this->isWebsiteRun($run))
                ->unique('digital_asset_id')
                ->keyBy('digital_asset_id');

        $allRows = $assets->map(function (DigitalAsset $asset) use ($runs): array {
            $pageSpeedReady = $this->pageSpeedReady($asset);
            /** @var CollectionRun|null $run */
            $run = $runs->get($asset->id);
            $collectable = filled($asset->primary_url) || filled($asset->domain);
            $wordpressConnection = $asset->connections->first(
                fn (CoreConnection $connection): bool => $connection->type === WordPressConnectorPairingService::CONNECTION_TYPE,
            );
            $wordpressDetected = str_contains(strtolower((string) $asset->cms), 'wordpress')
                || $wordpressConnection instanceof CoreConnection;
            $wordpressReady = $wordpressConnection instanceof CoreConnection
                && $wordpressConnection->enabled
                && data_get($wordpressConnection->config, 'pairing_state') === WordPressConnectorPairingService::PAIRED
                && $wordpressConnection->credential !== null;
            $collectors = $this->collectorSummaries($run, $collectable, $pageSpeedReady, $wordpressDetected, $wordpressReady);
            $requiredCollectors = collect($collectors)->where('optional', false);
            $requiredCompleted = $requiredCollectors->where('state', 'completed')->count();
            $sourceLabels = collect([$this->text('Public', 'Public')]);
            if ($wordpressDetected) {
                $sourceLabels->push('WordPress');
            }
            if ($pageSpeedReady) {
                $sourceLabels->push('PageSpeed');
            }

            return [
                'asset' => $asset,
                'run' => $run,
                'collectable' => $collectable,
                'page_speed_ready' => $pageSpeedReady,
                'wordpress_detected' => $wordpressDetected,
                'wordpress_ready' => $wordpressReady,
                'wordpress_connection' => $wordpressConnection,
                'collectors' => $collectors,
                'required_completed' => $requiredCompleted,
                'required_total' => $requiredCollectors->count(),
                'optional_ready' => $pageSpeedReady,
                'source_summary' => $sourceLabels->implode(' + '),
                'latest_rows_written' => (int) ($run?->datasetRuns?->sum('rows_written') ?? 0),
                'overall_state' => $this->overallState($run, $collectable),
                'run_status_label' => $run ? $this->runStatusLabel($run) : $this->text('Henüz veri çekilmedi', 'Never collected'),
                'last_run_at' => $run?->updated_at,
            ];
        });

        $stats = [
            'total' => $allRows->count(),
            'collect_ready' => $allRows->where('collectable', true)->count(),
            'completed' => $allRows->filter(fn (array $row): bool => ($row['run']?->status?->value ?? null) === 'completed')->count(),
            'running' => $allRows->where('overall_state', 'running')->count(),
            'attention' => $allRows->filter(fn (array $row): bool => in_array($row['overall_state'], ['attention', 'partial', 'needs_setup'], true))->count(),
            'never_collected' => $allRows->whereNull('run')->count(),
        ];

        $rows = $this->filterRows($allRows);
        $selectedRow = $this->selectedRow($allRows);

        if ($this->selectedAssetId !== null && $selectedRow === null) {
            abort(404);
        }

        if ($selectedRow !== null) {
            $selectedRow = $this->enrichSelectedRow($selectedRow, $storageRegistry);
        }

        $history = $selectedRow !== null
            ? $this->collectionHistory((int) $selectedRow['asset']->id)
            : collect();

        $liveConsole = $selectedRow !== null
            ? $this->liveConsole($selectedRow['run'])
            : null;

        $availableDatasets = $selectedRow === null
            ? collect()
            : collect($selectedRow['data_sources'])
                ->flatMap(fn (array $source): Collection => $source['datasets'])
                ->unique('id')
                ->values();
        $selectedDataset = $availableDatasets->first(
            fn (array $dataset): bool => $dataset['id'] === $this->selectedDatasetId,
        ) ?? $availableDatasets->first();
        $dataExplorer = $selectedRow !== null && is_array($selectedDataset) && $this->activeTab === 'data'
            ? $this->datasetExplorer(
                assetId: (int) $selectedRow['asset']->id,
                datasetId: (string) $selectedDataset['id'],
                schema: [
                    'table' => $selectedDataset['table'],
                    'fields' => $selectedDataset['fields'],
                    'system_field_count' => $selectedDataset['system_field_count'],
                ],
            )
            : null;

        return view('livewire.operator.integrations.website-integration-index', [
            'rows' => $rows,
            'selectedRow' => $selectedRow,
            'history' => $history,
            'liveConsole' => $liveConsole,
            'availableDatasets' => $availableDatasets,
            'selectedDataset' => $selectedDataset,
            'dataExplorer' => $dataExplorer,
            'stats' => $stats,
            'filters' => $this->filterOptions($allRows),
        ]);
    }

    private function pageSpeedReady(DigitalAsset $asset): bool
    {
        $pageSpeed = $asset->connections->first(
            fn (CoreConnection $connection): bool => $connection->type === PageSpeedConnectionProbeService::CONNECTION_TYPE,
        );
        $payload = $pageSpeed?->credential?->encrypted_payload;

        return $pageSpeed instanceof CoreConnection
            && $pageSpeed->enabled
            && is_array($payload)
            && filled($payload['api_key'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private function collectorSummaries(
        ?CollectionRun $run,
        bool $collectable,
        bool $pageSpeedReady,
        bool $wordpressDetected,
        bool $wordpressReady,
    ): array
    {
        $definitions = [
            ['key' => 'crawl', 'family' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, 'optional' => false],
            ['key' => 'html', 'family' => WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, 'optional' => false],
            ['key' => 'tls', 'family' => WebsiteRequestFamilyCatalog::FAMILY_DNS_TLS, 'optional' => false],
            ['key' => 'pagespeed', 'family' => WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED, 'optional' => true],
        ];
        if ($wordpressDetected) {
            $definitions[] = ['key' => 'wordpress', 'family' => WebsiteRequestFamilyCatalog::FAMILY_WP_REST, 'optional' => false];
        }
        $datasetRuns = $run?->datasetRuns ?? collect();

        return array_map(function (array $definition) use ($datasetRuns, $collectable, $pageSpeedReady, $wordpressReady): array {
            $familyRuns = $datasetRuns->filter(
                fn (CollectionDatasetRun $candidate): bool => $candidate->request_family_id === $definition['family'],
            );
            /** @var CollectionDatasetRun|null $datasetRun */
            $datasetRun = $familyRuns->first();
            $state = $definition['key'] === 'wordpress'
                ? $this->connectorCollectorState($familyRuns, $collectable, $wordpressReady)
                : $this->collectorState((string) $definition['key'], $datasetRun, $collectable, $pageSpeedReady, $wordpressReady);

            return [
                'key' => $definition['key'],
                'family' => $definition['family'],
                'dataset_run' => $datasetRun,
                'state' => $state,
                'optional' => $definition['optional'],
            ];
        }, $definitions);
    }

    /** @param Collection<int, CollectionDatasetRun> $runs */
    private function connectorCollectorState(Collection $runs, bool $collectable, bool $wordpressReady): string
    {
        if (! $collectable) {
            return 'needs_setup';
        }
        if (! $wordpressReady) {
            return 'connection_required';
        }
        if ($runs->isEmpty()) {
            return 'not_run';
        }

        $states = $runs->map(fn (CollectionDatasetRun $run): string => $this->datasetRunState($run));
        if ($states->contains('running')) {
            return 'running';
        }
        if ($states->every(fn (string $state): bool => $state === 'completed')) {
            return 'completed';
        }
        if ($states->contains('completed') || $states->contains('partial')) {
            return 'partial';
        }
        if ($states->contains('failed')) {
            return 'failed';
        }

        return $states->first() ?? 'not_run';
    }

    private function collectorState(
        string $key,
        ?CollectionDatasetRun $datasetRun,
        bool $collectable,
        bool $pageSpeedReady,
        bool $wordpressReady,
    ): string
    {
        if (! $collectable && $key !== 'pagespeed') {
            return 'needs_setup';
        }
        if ($key === 'pagespeed' && ! $pageSpeedReady) {
            return 'connection_required';
        }
        if ($key === 'wordpress' && ! $wordpressReady) {
            return 'connection_required';
        }
        if (! $datasetRun instanceof CollectionDatasetRun) {
            return 'not_run';
        }

        return $this->datasetRunState($datasetRun);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function enrichSelectedRow(array $row, DataPoolStorageRegistry $storageRegistry): array
    {
        /** @var DigitalAsset $asset */
        $asset = $row['asset'];
        /** @var CollectionRun|null $run */
        $run = $row['run'];
        $datasetIds = $this->publicDatasetIds();
        $pageSpeedDatasetIds = $this->pageSpeedDatasetIds();
        $connectorDatasetIds = $this->connectorDatasetIds();
        $allDatasetIds = array_values(array_unique(array_merge($datasetIds, $pageSpeedDatasetIds, $connectorDatasetIds)));
        $datasetRunIds = $run?->datasetRuns?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];

        $batches = $datasetRunIds === []
            ? collect()
            : DatasetWriteBatch::query()
                ->whereIn('dataset_run_id', $datasetRunIds)
                ->whereIn('dataset_id', $allDatasetIds)
                ->orderBy('id')
                ->get()
                ->groupBy('dataset_id');

        $materializations = DatasetMaterialization::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('dataset_id', $allDatasetIds)
            ->get()
            ->keyBy('dataset_id');

        $publicDatasets = collect($datasetIds)->map(fn (string $datasetId): array => $this->datasetSummary(
            datasetId: $datasetId,
            asset: $asset,
            run: $run,
            pageSpeedReady: (bool) $row['page_speed_ready'],
            batches: $batches->get($datasetId, collect()),
            materialization: $materializations->get($datasetId),
            storageRegistry: $storageRegistry,
        ))->values();
        $pageSpeedDatasets = collect($pageSpeedDatasetIds)->map(fn (string $datasetId): array => $this->datasetSummary(
            datasetId: $datasetId,
            asset: $asset,
            run: $run,
            pageSpeedReady: (bool) $row['page_speed_ready'],
            batches: $batches->get($datasetId, collect()),
            materialization: $materializations->get($datasetId),
            storageRegistry: $storageRegistry,
        ))->values();

        $connectorDatasets = ! (bool) $row['wordpress_detected']
            ? collect()
            : collect($connectorDatasetIds)->map(fn (string $datasetId): array => $this->datasetSummary(
                datasetId: $datasetId,
                asset: $asset,
                run: $run,
                pageSpeedReady: true,
                batches: $batches->get($datasetId, collect()),
                materialization: $materializations->get($datasetId),
                storageRegistry: $storageRegistry,
            ))->values();
        $publicState = $this->sourceState($publicDatasets);
        $connectorState = ! (bool) $row['wordpress_ready']
            ? 'connection_required'
            : $this->sourceState($connectorDatasets);
        $pageSpeedState = ! (bool) $row['page_speed_ready']
            ? 'connection_required'
            : $this->sourceState($pageSpeedDatasets);

        $sources = collect([
            $this->sourceSummary(
                key: 'public_web',
                label: $this->text('Public Site Taraması', 'Public Site Crawl'),
                description: $this->text('Yayınlanan HTTP, HTML, bağlantı ve SSL/TLS verilerini dışarıdan toplar.', 'Collects published HTTP, HTML, link, and SSL/TLS facts from outside the site.'),
                state: $publicState,
                connectionLabel: $this->text('Bağlantı gerektirmez', 'No connection required'),
                datasets: $publicDatasets,
                optional: false,
            ),
        ]);

        if ((bool) $row['wordpress_detected']) {
            $sources->push($this->sourceSummary(
                key: 'site_connector',
                label: 'WordPress Connector',
                description: $this->text('CMS’in içerik, eklenti, tema, taksonomi ve SEO envanterini salt okunur toplar.', 'Collects the CMS content, plugin, theme, taxonomy, and SEO inventory read-only.'),
                state: $connectorState,
                connectionLabel: (bool) $row['wordpress_ready']
                    ? $this->text('Bağlı', 'Paired')
                    : $this->text('Eşleştirme gerekli', 'Pairing required'),
                datasets: $connectorDatasets,
                optional: false,
            ));
        }

        $sources->push($this->sourceSummary(
            key: 'pagespeed',
            label: 'PageSpeed',
            description: $this->text('Lighthouse performans ölçümlerini isteğe bağlı API kaynağından toplar.', 'Collects Lighthouse performance measurements from an optional API source.'),
            state: $pageSpeedState,
            connectionLabel: (bool) $row['page_speed_ready']
                ? $this->text('Bağlı', 'Connected')
                : $this->text('İsteğe bağlı · bağlı değil', 'Optional · not connected'),
            datasets: $pageSpeedDatasets,
            optional: true,
        ));

        $row['data_sources'] = $sources->values();
        $htmlCoverage = $this->htmlCoverageMetrics((int) $asset->id, $run?->id);
        $row['headline_metrics'] = [
            'urls' => (int) data_get($publicDatasets->firstWhere('id', 'website_url'), 'current_rows', 0),
            'html_pages' => $htmlCoverage['pages'],
            'html_changes' => $htmlCoverage['changed'],
            'wordpress_objects' => (int) data_get($connectorDatasets->firstWhere('id', 'website_cms_object_snapshot'), 'current_rows', 0),
            'last_run_at' => $run?->updated_at,
        ];
        $row['last_run_changes'] = [
            'inserted' => $sources->flatMap(fn (array $source): Collection => $source['datasets'])->sum('inserted_rows'),
            'updated' => $sources->flatMap(fn (array $source): Collection => $source['datasets'])->sum('updated_rows'),
            'unchanged' => $sources->flatMap(fn (array $source): Collection => $source['datasets'])->sum('unchanged_rows'),
            'failed_batches' => $sources->flatMap(fn (array $source): Collection => $source['datasets'])->sum('failed_batches'),
        ];

        return $row;
    }

    /** @return array{pages: int, changed: int} */
    private function htmlCoverageMetrics(int $assetId, ?int $collectionRunId): array
    {
        if (! Schema::hasTable('website_html_snapshot')) {
            return ['pages' => 0, 'changed' => 0];
        }

        try {
            $pages = DB::table('website_html_snapshot')
                ->where('digital_asset_id', $assetId)
                ->distinct()
                ->count('url');
            $changed = $collectionRunId === null
                ? 0
                : DB::table('website_html_snapshot')
                    ->where('digital_asset_id', $assetId)
                    ->where('last_collection_run_id', $collectionRunId)
                    ->where('change_state', 'changed')
                    ->distinct()
                    ->count('url');

            return ['pages' => (int) $pages, 'changed' => (int) $changed];
        } catch (Throwable $exception) {
            report($exception);

            return ['pages' => 0, 'changed' => 0];
        }
    }

    /** @return list<string> */
    private function publicDatasetIds(): array
    {
        $ids = [];
        foreach (WebsiteRequestFamilyCatalog::publicFamilies() as $family) {
            if ($family === WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED) {
                continue;
            }
            foreach ((array) WebsiteRequestFamilyCatalog::definition($family)['dataset_ids'] as $datasetId) {
                if ((string) $datasetId !== 'website_crawl_issue_snapshot') {
                    $ids[] = (string) $datasetId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function pageSpeedDatasetIds(): array
    {
        return array_values(array_map(
            'strval',
            (array) WebsiteRequestFamilyCatalog::definition(WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED)['dataset_ids'],
        ));
    }

    /** @return list<string> */
    private function connectorDatasetIds(): array
    {
        return array_values(array_map(
            'strval',
            (array) WebsiteRequestFamilyCatalog::definition(WebsiteRequestFamilyCatalog::FAMILY_WP_REST)['dataset_ids'],
        ));
    }

    /** @param Collection<int, array<string, mixed>> $datasets */
    private function sourceState(Collection $datasets): string
    {
        if ($datasets->isEmpty()) {
            return 'not_run';
        }
        if ($datasets->where('state', 'running')->isNotEmpty()) {
            return 'running';
        }
        if ($datasets->every(fn (array $dataset): bool => $dataset['state'] === 'completed')) {
            return 'completed';
        }
        if ($datasets->where('state', 'completed')->isNotEmpty()
            || $datasets->whereIn('state', ['partial', 'skipped'])->isNotEmpty()) {
            return 'partial';
        }
        if ($datasets->whereIn('state', ['failed', 'needs_setup'])->isNotEmpty()) {
            return 'attention';
        }

        return 'not_run';
    }

    /**
     * @param Collection<int, array<string, mixed>> $datasets
     * @return array<string, mixed>
     */
    private function sourceSummary(
        string $key,
        string $label,
        string $description,
        string $state,
        string $connectionLabel,
        Collection $datasets,
        bool $optional,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'state' => $state,
            'status_label' => $this->sourceGroupStatusLabel($state),
            'connection_label' => $connectionLabel,
            'datasets' => $datasets,
            'completed' => $datasets->where('state', 'completed')->count(),
            'total' => $datasets->count(),
            'optional' => $optional,
            'last_collected_at' => $datasets->pluck('last_collected_at')->filter()->sortDesc()->first(),
        ];
    }

    /** @param Collection<int, DatasetWriteBatch> $batches @return array<string, mixed> */
    private function datasetSummary(
        string $datasetId,
        DigitalAsset $asset,
        ?CollectionRun $run,
        bool $pageSpeedReady,
        Collection $batches,
        mixed $materialization,
        DataPoolStorageRegistry $storageRegistry,
    ): array {
        $families = $this->familiesForDataset($datasetId);
        $familyRuns = $run?->datasetRuns?->filter(
            fn (CollectionDatasetRun $datasetRun): bool => in_array($datasetRun->request_family_id, $families, true),
        ) ?? collect();
        $exactRuns = $familyRuns->filter(
            fn (CollectionDatasetRun $datasetRun): bool => (string) $datasetRun->dataset_contract_id === $datasetId,
        );
        if ($exactRuns->isNotEmpty()) {
            $familyRuns = $exactRuns;
        }
        $state = $this->datasetState(
            $datasetId,
            $familyRuns,
            $pageSpeedReady,
            filled($asset->primary_url) || filled($asset->domain),
        );
        $processedRows = $batches->sum(fn (DatasetWriteBatch $batch): int => max(0, (int) $batch->rows_received));
        $insertedRows = $batches->sum(fn (DatasetWriteBatch $batch): int => max(0, (int) $batch->rows_inserted));
        $updatedRows = $batches->sum(fn (DatasetWriteBatch $batch): int => max(0, (int) $batch->rows_updated));
        $unchangedRows = $batches->sum(fn (DatasetWriteBatch $batch): int => max(0, (int) $batch->rows_unchanged));
        $successfulBatches = $batches->filter(fn (DatasetWriteBatch $batch): bool => ($batch->status?->value ?? null) === 'committed')->count();
        $failedBatches = $batches->filter(fn (DatasetWriteBatch $batch): bool => ($batch->status?->value ?? null) === 'failed')->count();
        $currentRows = max(0, (int) ($materialization?->row_count_approx ?? 0));
        $schema = $this->datasetSchema($datasetId, $storageRegistry);

        return [
            'id' => $datasetId,
            'label' => $this->datasetLabel($datasetId),
            'description' => $this->datasetDescription($datasetId),
            'row_label' => $this->datasetRowLabel($datasetId),
            'state' => $state,
            'status_label' => $this->datasetStatusLabel($state),
            'tone' => $this->sourceTone($state),
            'current_rows' => $currentRows,
            'processed_rows' => $processedRows,
            'inserted_rows' => $insertedRows,
            'updated_rows' => $updatedRows,
            'unchanged_rows' => $unchangedRows,
            'successful_batches' => $successfulBatches,
            'failed_batches' => $failedBatches,
            'last_collected_at' => $materialization?->last_collected_at,
            'families' => $families,
            'collectors' => array_values(array_map(fn (string $family): string => $this->familyLabel($family), $families)),
            'fields' => $schema['fields'],
            'system_field_count' => $schema['system_field_count'],
            'table' => $schema['table'],
            'result_detail' => $this->datasetResultDetail($state, $currentRows, $processedRows),
        ];
    }

    /** @param Collection<int, CollectionDatasetRun> $familyRuns */
    private function datasetState(string $datasetId, Collection $familyRuns, bool $pageSpeedReady, bool $collectable): string
    {
        if (! $collectable && $datasetId !== 'website_performance_measurement') {
            return 'needs_setup';
        }
        if ($datasetId === 'website_performance_measurement' && ! $pageSpeedReady) {
            return 'connection_required';
        }
        if ($familyRuns->isEmpty()) {
            return 'not_run';
        }

        $states = $familyRuns->map(fn (CollectionDatasetRun $run): string => $this->datasetRunState($run));
        if ($states->contains('running')) {
            return 'running';
        }
        if ($states->every(fn (string $state): bool => $state === 'completed')) {
            return 'completed';
        }
        if ($states->contains('completed') || $states->contains('partial')) {
            return 'partial';
        }
        if ($states->contains('failed')) {
            return 'failed';
        }
        if ($states->contains('not_eligible')) {
            return 'not_eligible';
        }
        if ($states->contains('skipped')) {
            return 'skipped';
        }

        return 'not_run';
    }

    private function datasetRunState(CollectionDatasetRun $run): string
    {
        return match ($run->status?->value) {
            'completed' => 'completed',
            'partial' => 'partial',
            'failed', 'cancelled' => 'failed',
            'queued', 'running', 'retrying', 'cancellation_requested' => 'running',
            'skipped' => 'skipped',
            'not_eligible' => 'not_eligible',
            default => 'not_run',
        };
    }

    /** @return list<string> */
    private function familiesForDataset(string $datasetId): array
    {
        $families = [];
        foreach (WebsiteRequestFamilyCatalog::supportedFamilies() as $family) {
            if (in_array($datasetId, (array) WebsiteRequestFamilyCatalog::definition($family)['dataset_ids'], true)) {
                $families[] = $family;
            }
        }

        return $families;
    }

    /** @return array{table: ?string, fields: list<array<string, mixed>>, system_field_count: int} */
    private function datasetSchema(string $datasetId, DataPoolStorageRegistry $storageRegistry): array
    {
        try {
            if (! $storageRegistry->hasPhysicalTable($datasetId)) {
                return ['table' => null, 'fields' => [], 'system_field_count' => 0];
            }

            $physical = $storageRegistry->physicalDataset($datasetId);
            $columns = collect((array) ($physical['columns'] ?? []));
            $systemRoles = ['provenance', 'extension'];
            $systemNames = ['digital_asset_id', 'external_resource_id', 'record_fingerprint'];
            $visible = $columns->reject(fn ($column): bool => ! is_array($column)
                || in_array((string) ($column['role'] ?? ''), $systemRoles, true)
                || in_array((string) ($column['name'] ?? ''), $systemNames, true));

            $fields = $visible->map(function (array $column): array {
                $name = (string) ($column['name'] ?? '');

                return [
                    'name' => $name,
                    'label' => $this->fieldLabel($name),
                    'type' => (string) ($column['type'] ?? '—'),
                    'nullable' => (bool) ($column['nullable'] ?? true),
                    'role' => (string) ($column['role'] ?? 'dimension'),
                ];
            })->values()->all();

            return [
                'table' => (string) ($physical['table'] ?? ''),
                'fields' => $fields,
                'system_field_count' => max(0, $columns->count() - count($fields)),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['table' => null, 'fields' => [], 'system_field_count' => 0];
        }
    }

    /**
     * @param array{table: ?string, fields: list<array<string, mixed>>, system_field_count: int} $schema
     * @return array<string, mixed>
     */
    private function datasetExplorer(int $assetId, string $datasetId, array $schema): array
    {
        $table = $schema['table'];
        if (! is_string($table) || $table === '') {
            return $this->emptyExplorer('unavailable');
        }

        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'digital_asset_id')) {
                return $this->emptyExplorer('unavailable');
            }

            $availableColumns = Schema::getColumnListing($table);
            $fields = $this->datasetPreviewFields($datasetId, $schema['fields'])
                ->filter(function (array $field) use ($availableColumns): bool {
                    $column = (string) ($field['column'] ?? $field['name']);

                    return in_array($column, $availableColumns, true)
                        && (! isset($field['metadata_path']) || in_array('metadata', $availableColumns, true));
                })
                ->take(9)
                ->values();
            if ($fields->isEmpty()) {
                return $this->emptyExplorer('unavailable');
            }

            $columnNames = $fields
                ->map(fn (array $field): string => (string) ($field['column'] ?? $field['name']))
                ->when(
                    $fields->contains(fn (array $field): bool => isset($field['metadata_path'])),
                    fn (Collection $columns): Collection => $columns->push('metadata'),
                )
                ->unique()
                ->values()
                ->all();
            $query = DB::table($table)->where('digital_asset_id', $assetId);
            $search = mb_strtolower(trim($this->dataSearch));
            $searchable = $fields->filter(function (array $field): bool {
                if (isset($field['metadata_path'])) {
                    return false;
                }
                $type = mb_strtolower((string) ($field['type'] ?? ''));

                return str_contains($type, 'string')
                    || str_contains($type, 'text')
                    || str_contains($type, 'char');
            })->map(fn (array $field): string => (string) ($field['column'] ?? $field['name']))->unique();
            if ($search !== '' && $searchable->isNotEmpty()) {
                $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $query->where(function ($nested) use ($operator, $search, $searchable): void {
                    foreach ($searchable as $column) {
                        $nested->orWhere((string) $column, $operator, '%'.$search.'%');
                    }
                });
            }

            $total = (clone $query)->count();
            $perPage = 25;
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, $this->dataPage), $lastPage);
            $query->select($columnNames);
            if ($datasetId === 'website_cms_object_snapshot' && in_array('object_type', $availableColumns, true)) {
                $query->orderByRaw("CASE WHEN object_type = 'attachment' THEN 1 ELSE 0 END");
                if (in_array('modified_at', $availableColumns, true)) {
                    $query->orderByDesc('modified_at');
                }
            } elseif ($datasetId === 'website_cms_seo_snapshot' && in_array('seo_provider', $availableColumns, true)) {
                $query->orderByRaw('CASE WHEN seo_provider IS NULL THEN 1 ELSE 0 END');
                $query->orderByDesc('observed_at');
            } elseif (in_array('last_collected_at', $availableColumns, true)) {
                $query->orderByDesc('last_collected_at');
            } elseif (in_array('observed_at', $availableColumns, true)) {
                $query->orderByDesc('observed_at');
            }

            $rows = $query
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(function ($record) use ($fields): array {
                    $data = (array) $record;
                    $metadata = $this->decodedMetadata($data['metadata'] ?? null);
                    $normalized = [];
                    foreach ($fields as $field) {
                        $name = (string) $field['name'];
                        $value = isset($field['metadata_path'])
                            ? data_get($metadata, (string) $field['metadata_path'])
                            : ($data[(string) ($field['column'] ?? $name)] ?? null);
                        $normalized[$name] = $this->previewValue($value);
                    }

                    return $normalized;
                })->values()->all();

            return [
                'state' => $rows === [] ? 'empty' : 'available',
                'columns' => $fields->map(fn (array $field): array => ['name' => $field['name'], 'label' => $field['label']])->all(),
                'rows' => $rows,
                'page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($total, $page * $perPage),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->emptyExplorer('unavailable');
        }
    }

    /** @return array<string, mixed> */
    private function emptyExplorer(string $state): array
    {
        return [
            'state' => $state,
            'columns' => [],
            'rows' => [],
            'page' => 1,
            'last_page' => 1,
            'total' => 0,
            'from' => 0,
            'to' => 0,
        ];
    }

    private function previewValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? $this->text('Evet', 'Yes') : $this->text('Hayır', 'No');
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '—';
        }

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 137).'…' : $text;
    }

    /** @param list<array<string, mixed>> $schemaFields @return Collection<int, array<string, mixed>> */
    private function datasetPreviewFields(string $datasetId, array $schemaFields): Collection
    {
        $field = fn (
            string $name,
            string $label,
            string $type = 'text',
            ?string $column = null,
            ?string $metadataPath = null,
        ): array => array_filter([
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'column' => $column ?? $name,
            'metadata_path' => $metadataPath,
        ], static fn (mixed $value): bool => $value !== null);

        $definitions = match ($datasetId) {
            'website_url' => [
                $field('normalized_url', 'URL'),
                $field('source', $this->text('Keşif Kaynağı', 'Discovery Source'), column: 'metadata', metadataPath: 'source'),
            ],
            'website_http_snapshot' => [
                $field('url', 'URL'),
                $field('status_code', $this->text('HTTP Durum Kodu', 'HTTP Status Code'), 'integer', 'metadata', 'status_code'),
                $field('final_url', $this->text('Son URL', 'Final URL'), column: 'metadata', metadataPath: 'final_url'),
                $field('redirect_count', $this->text('Yönlendirme Sayısı', 'Redirect Count'), 'integer', 'metadata', 'redirect_count'),
                $field('content_type', $this->text('İçerik Türü', 'Content Type'), column: 'metadata', metadataPath: 'content_type'),
                $field('ok', $this->text('Erişilebilir', 'Reachable'), 'boolean', 'metadata', 'ok'),
                $field('error', $this->text('Hata', 'Error'), column: 'metadata', metadataPath: 'error'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_html_snapshot' => [
                $field('url', 'URL'),
                $field('status_code', $this->fieldLabel('status_code'), 'integer'),
                $field('change_state', $this->fieldLabel('change_state')),
                $field('html_hash', $this->fieldLabel('html_hash')),
                $field('previous_html_hash', $this->fieldLabel('previous_html_hash')),
                $field('html_bytes', $this->fieldLabel('html_bytes'), 'bigint'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_metadata_snapshot' => [
                $field('url', 'URL'),
                $field('title', $this->fieldLabel('title'), column: 'metadata', metadataPath: 'title'),
                $field('meta_description', $this->fieldLabel('meta_description'), column: 'metadata', metadataPath: 'meta_description'),
                $field('canonical_hrefs', 'Canonical URL', column: 'metadata', metadataPath: 'canonical_hrefs'),
                $field('meta_robots', $this->fieldLabel('robots'), column: 'metadata', metadataPath: 'meta_robots'),
                $field('title_present', $this->text('Başlık Mevcut', 'Title Present'), 'boolean', 'metadata', 'title_present'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_heading_snapshot' => [
                $field('url', 'URL'),
                $field('h1', 'H1', column: 'metadata', metadataPath: 'h1'),
                $field('h1_present', $this->text('H1 Mevcut', 'H1 Present'), 'boolean', 'metadata', 'h1_present'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_schema_snapshot' => [
                $field('url', 'URL'),
                $field('types', $this->text('Schema Türleri', 'Schema Types'), column: 'metadata', metadataPath: 'types'),
                $field('block_count', $this->text('Blok Sayısı', 'Block Count'), 'integer', 'metadata', 'block_count'),
                $field('parse_ok_count', $this->text('Geçerli Blok', 'Valid Blocks'), 'integer', 'metadata', 'parse_ok_count'),
                $field('malformed_count', $this->text('Hatalı Blok', 'Malformed Blocks'), 'integer', 'metadata', 'malformed_count'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_content_stats' => [
                $field('url', 'URL'),
                $field('word_count', $this->fieldLabel('word_count'), 'integer', 'metadata', 'word_count'),
                $field('paragraph_count', $this->fieldLabel('paragraph_count'), 'integer', 'metadata', 'paragraph_count'),
                $field('visible_text_length', $this->fieldLabel('visible_text_length'), 'integer', 'metadata', 'visible_text_length'),
                $field('language', $this->fieldLabel('language'), column: 'metadata', metadataPath: 'language'),
                $field('thin_content_hint', $this->fieldLabel('thin_content_hint'), 'boolean', 'metadata', 'thin_content_hint'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_link_edge' => [
                $field('source_url', $this->fieldLabel('source_url')),
                $field('target_url', $this->fieldLabel('target_url')),
                $field('is_internal', $this->fieldLabel('is_internal'), 'boolean'),
                $field('anchor_text', $this->fieldLabel('anchor_text')),
                $field('rel', 'Rel'),
                $field('nofollow', 'Nofollow', 'boolean'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_infra_snapshot' => [
                $field('host', 'Host', column: 'metadata', metadataPath: 'host'),
                $field('present', $this->text('TLS Mevcut', 'TLS Present'), 'boolean', 'metadata', 'present'),
                $field('issuer', $this->text('Sertifika Sağlayıcısı', 'Certificate Issuer'), column: 'metadata', metadataPath: 'tls.issuer_common_name'),
                $field('valid_from', $this->text('Geçerlilik Başlangıcı', 'Valid From'), column: 'metadata', metadataPath: 'tls.valid_from'),
                $field('valid_to', $this->text('Geçerlilik Bitişi', 'Valid To'), column: 'metadata', metadataPath: 'tls.valid_to'),
                $field('error', $this->text('Hata', 'Error'), column: 'metadata', metadataPath: 'tls.error_class'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_cms_site_snapshot' => [
                $field('site_url', 'Site URL'),
                $field('wordpress_version', 'WordPress'),
                $field('php_version', 'PHP'),
                $field('active_theme', $this->fieldLabel('active_theme')),
                $field('rest_state', 'REST'),
                $field('cron_state', 'Cron'),
                $field('site_health_recommended_count', $this->text('Önerilen Kontrol', 'Recommended Checks'), 'integer'),
                $field('site_health_critical_count', $this->text('Kritik Kontrol', 'Critical Checks'), 'integer'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_cms_object_snapshot' => [
                $field('object_type', $this->fieldLabel('object_type')),
                $field('title', $this->fieldLabel('title')),
                $field('status', $this->fieldLabel('status')),
                $field('slug', 'Slug'),
                $field('permalink', 'URL'),
                $field('published_at', $this->fieldLabel('published_at'), 'timestamptz'),
                $field('modified_at', $this->fieldLabel('modified_at'), 'timestamptz'),
                $field('language', $this->fieldLabel('language')),
            ],
            'website_cms_extension_snapshot' => [
                $field('extension_type', $this->fieldLabel('extension_type')),
                $field('name', $this->text('Ad', 'Name')),
                $field('version', $this->text('Sürüm', 'Version')),
                $field('status', $this->fieldLabel('status')),
                $field('update_available', $this->fieldLabel('update_available'), 'boolean'),
                $field('available_version', $this->fieldLabel('available_version')),
                $field('auto_update', $this->text('Otomatik Güncelleme', 'Auto Update'), 'boolean'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_cms_taxonomy_snapshot' => [
                $field('taxonomy', $this->fieldLabel('taxonomy')),
                $field('name', $this->text('Ad', 'Name')),
                $field('slug', 'Slug'),
                $field('parent_id', $this->text('Üst Terim', 'Parent Term'), 'integer'),
                $field('content_count', $this->text('İçerik Sayısı', 'Content Count'), 'integer'),
                $field('language', $this->fieldLabel('language')),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            'website_cms_seo_snapshot' => [
                $field('object_type', $this->fieldLabel('object_type')),
                $field('permalink', 'URL'),
                $field('seo_provider', $this->fieldLabel('seo_provider')),
                $field('seo_title', $this->fieldLabel('seo_title')),
                $field('meta_description', $this->fieldLabel('meta_description')),
                $field('canonical_url', $this->fieldLabel('canonical_url')),
                $field('robots', $this->fieldLabel('robots')),
                $field('language', $this->fieldLabel('language')),
            ],
            'website_performance_measurement' => [
                $field('url', 'URL'),
                $field('strategy', $this->fieldLabel('strategy')),
                $field('lcp_ms', 'LCP (ms)', 'integer', 'metadata', 'lcp_ms'),
                $field('final_url', $this->fieldLabel('final_url'), column: 'metadata', metadataPath: 'final_url'),
                $field('fetch_time', $this->text('Ölçüm Zamanı', 'Measurement Time'), column: 'metadata', metadataPath: 'fetch_time'),
                $field('observed_at', $this->fieldLabel('observed_at'), 'timestamptz'),
            ],
            default => collect($schemaFields)->map(fn (array $schemaField): array => array_merge($schemaField, [
                'column' => (string) $schemaField['name'],
            ]))->take(9)->all(),
        };

        return collect($definitions);
    }

    /** @return array<string, mixed> */
    private function decodedMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_object($metadata)) {
            return (array) $metadata;
        }
        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function datasetResultDetail(string $state, int $currentRows, int $processedRows): string
    {
        return match ($state) {
            'completed' => $currentRows > 0
                ? $this->text("Mevcut veri havuzunda yaklaşık {$currentRows} kayıt var.", "Approximately {$currentRows} current records are available in the data pool.")
                : $this->text('Kontrol tamamlandı; bu dataset için kayıt oluşmamış olabilir.', 'Check completed; this dataset may legitimately contain no records.'),
            'running' => $this->text('Bu datasetin bağlı olduğu veri çekimi devam ediyor.', 'Collection contributing to this dataset is still running.'),
            'partial' => $this->text('Dataset kısmi sonuç üretti; bağlı çekimlerden en az biri tam tamamlanmadı.', 'The dataset has partial results because at least one contributing collector did not complete fully.'),
            'failed' => $this->text('Bu datasetin bağlı olduğu veri çekimi başarısız oldu.', 'The collector contributing to this dataset failed.'),
            'connection_required' => $this->text('PageSpeed API bağlantısı yapılmadan bu dataset üretilemez.', 'This dataset cannot be produced until PageSpeed API is connected.'),
            'needs_setup' => $this->text('Web sitesi URL/domain bilgisi tamamlanmadan bu dataset üretilemez.', 'This dataset cannot be produced until the website URL/domain is configured.'),
            'not_eligible' => $this->text('Bu dataset mevcut koşullarda bu web sitesi için uygun değil.', 'This dataset is not eligible for this website under current conditions.'),
            'skipped' => $this->text('Bu dataset son çekimde atlandı.', 'This dataset was skipped in the latest run.'),
            default => $processedRows > 0
                ? $this->text("Son çekimde {$processedRows} kayıt işlendi.", "{$processedRows} records were processed in the latest run.")
                : $this->text('Bu dataset için henüz veri çekimi yapılmadı.', 'No collection has produced this dataset yet.'),
        };
    }

    private function datasetLabel(string $datasetId): string
    {
        return match ($datasetId) {
            'website_url' => $this->text('URL ve Sayfalar', 'URLs and Pages'),
            'website_http_snapshot' => $this->text('HTTP Durumları', 'HTTP Statuses'),
            'website_html_snapshot' => $this->text('Yayınlanan HTML Sürümleri', 'Published HTML Versions'),
            'website_metadata_snapshot' => $this->text('Başlık ve Meta Verileri', 'Titles and Metadata'),
            'website_heading_snapshot' => $this->text('Başlık Hiyerarşisi', 'Heading Hierarchy'),
            'website_schema_snapshot' => $this->text('Yapısal Veri (Schema)', 'Structured Data (Schema)'),
            'website_content_stats' => $this->text('İçerik İstatistikleri', 'Content Statistics'),
            'website_link_edge' => $this->text('İç / Dış Bağlantılar', 'Internal / External Links'),
            'website_infra_snapshot' => $this->text('SSL/TLS Altyapısı', 'SSL/TLS Infrastructure'),
            'website_performance_measurement' => $this->text('PageSpeed Performansı', 'PageSpeed Performance'),
            'website_cms_site_snapshot' => $this->text('WordPress Site Bilgileri', 'WordPress Site Facts'),
            'website_cms_object_snapshot' => $this->text('WordPress İçerik Envanteri', 'WordPress Content Inventory'),
            'website_cms_extension_snapshot' => $this->text('WordPress Eklenti ve Tema Envanteri', 'WordPress Plugin and Theme Inventory'),
            'website_cms_taxonomy_snapshot' => $this->text('WordPress Taksonomileri', 'WordPress Taxonomies'),
            'website_cms_seo_snapshot' => $this->text('WordPress SEO Alanları', 'WordPress SEO Fields'),
            default => str($datasetId)->replace('_', ' ')->title()->toString(),
        };
    }

    private function datasetDescription(string $datasetId): string
    {
        return match ($datasetId) {
            'website_url' => $this->text('Keşfedilen ve normalize edilen web sayfası adresleri.', 'Discovered and normalized website URLs.'),
            'website_http_snapshot' => $this->text('HTTP yanıt kodu, yönlendirme ve erişilebilirlik gözlemleri.', 'HTTP response, redirect, and availability observations.'),
            'website_html_snapshot' => $this->text('Ziyaretçinin aldığı nihai HTML gövdesi; hash, önceki sürüm ve değişim durumu ile özel depoda sürümlenir.', 'Final visitor-facing HTML body, versioned in private storage with its hash, previous version, and change state.'),
            'website_metadata_snapshot' => $this->text('Yayınlanan HTML içindeki title, meta description, canonical ve robots sinyalleri.', 'Title, meta description, canonical, and robots signals emitted in published HTML.'),
            'website_heading_snapshot' => $this->text('Yayınlanan HTML içindeki H1 başlığı ve mevcutluk durumu.', 'H1 heading and presence state in published HTML.'),
            'website_schema_snapshot' => $this->text('Sayfadaki yapılandırılmış veri / schema gözlemleri.', 'Structured data / schema observations found on the page.'),
            'website_content_stats' => $this->text('Kelime, paragraf, görünür metin ve içerik yoğunluğu istatistikleri.', 'Word, paragraph, visible text, and content density statistics.'),
            'website_link_edge' => $this->text('Sayfalar arasındaki iç bağlantılar ve harici link ilişkileri.', 'Internal page links and external link relationships.'),
            'website_infra_snapshot' => $this->text('Alan adı hostu, sertifika ve SSL/TLS altyapı gözlemleri.', 'Host, certificate, and SSL/TLS infrastructure observations.'),
            'website_performance_measurement' => $this->text('Google PageSpeed / Lighthouse performans ölçümleri.', 'Google PageSpeed / Lighthouse performance measurements.'),
            'website_cms_site_snapshot' => $this->text('WordPress, PHP, tema ve güvenli çalışma ayarları snapshot’ı.', 'Snapshot of WordPress, PHP, theme, and safe runtime settings.'),
            'website_cms_object_snapshot' => $this->text('WordPress sayfa, yazı ve diğer CMS nesnelerinin authenticated envanteri.', 'Authenticated inventory of WordPress pages, posts, and other CMS objects.'),
            'website_cms_extension_snapshot' => $this->text('Eklenti ve tema sürümü, aktiflik ve güncelleme durumu.', 'Plugin and theme versions, activation, and update state.'),
            'website_cms_taxonomy_snapshot' => $this->text('Kategori, etiket, özel taksonomi ve dil metadata’sı.', 'Categories, tags, custom taxonomies, and language metadata.'),
            'website_cms_seo_snapshot' => $this->text('Allowlist içindeki SEO eklentisi title, description, canonical ve robots alanları.', 'Allowlisted SEO plugin title, description, canonical, and robots fields.'),
            default => $datasetId,
        };
    }

    private function datasetRowLabel(string $datasetId): string
    {
        return match ($datasetId) {
            'website_url' => $this->text('keşfedilmiş URL', 'discovered URLs'),
            'website_http_snapshot' => $this->text('HTTP gözlemi', 'HTTP observations'),
            'website_html_snapshot' => $this->text('HTML sürüm gözlemi', 'HTML version observations'),
            'website_metadata_snapshot', 'website_heading_snapshot', 'website_schema_snapshot',
            'website_content_stats', 'website_infra_snapshot' => $this->text('sayfa gözlemi', 'page observations'),
            'website_link_edge' => $this->text('bağlantı ilişkisi', 'link relationships'),
            'website_performance_measurement' => $this->text('performans ölçümü', 'performance measurements'),
            'website_cms_site_snapshot' => $this->text('site gözlemi', 'site observations'),
            'website_cms_object_snapshot' => $this->text('CMS nesnesi', 'CMS objects'),
            'website_cms_extension_snapshot' => $this->text('eklenti/tema kaydı', 'plugin/theme records'),
            'website_cms_taxonomy_snapshot' => $this->text('taksonomi kaydı', 'taxonomy records'),
            'website_cms_seo_snapshot' => $this->text('SEO alan kaydı', 'SEO field records'),
            default => $this->text('kayıt', 'rows'),
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'url', 'requested_url', 'normalized_url', 'permalink' => 'URL',
            'source_url' => $this->text('Kaynak Sayfa', 'Source Page'),
            'target_url', 'normalized_target_url' => $this->text('Hedef URL', 'Target URL'),
            'final_url' => $this->text('Son URL', 'Final URL'),
            'status_code', 'http_status' => $this->text('HTTP Durum Kodu', 'HTTP Status Code'),
            'title' => $this->text('Başlık', 'Title'),
            'meta_description' => $this->text('Meta Açıklaması', 'Meta Description'),
            'canonical_url', 'canonical' => 'Canonical URL',
            'robots', 'robots_directive' => $this->text('Robots Direktifi', 'Robots Directive'),
            'html_lang', 'language' => $this->text('Sayfa Dili', 'Page Language'),
            'word_count' => $this->text('Kelime Sayısı', 'Word Count'),
            'paragraph_count' => $this->text('Paragraf Sayısı', 'Paragraph Count'),
            'visible_text_length' => $this->text('Görünür Metin Uzunluğu', 'Visible Text Length'),
            'thin_content_hint' => $this->text('İnce İçerik Sinyali', 'Thin Content Hint'),
            'heading_level', 'level' => $this->text('Başlık Seviyesi', 'Heading Level'),
            'heading_text', 'text' => $this->text('Başlık Metni', 'Heading Text'),
            'message' => $this->text('Açıklama', 'Message'),
            'is_internal' => $this->text('İç Bağlantı mı?', 'Internal Link?'),
            'anchor_text' => $this->text('Bağlantı Metni', 'Anchor Text'),
            'nofollow' => 'Nofollow',
            'rel' => 'Rel',
            'observed_at' => $this->text('Gözlem Zamanı', 'Observed At'),
            'change_state' => $this->text('HTML Değişimi', 'HTML Change'),
            'html_hash' => $this->text('HTML Hash', 'HTML Hash'),
            'previous_html_hash' => $this->text('Önceki HTML Hash', 'Previous HTML Hash'),
            'html_bytes' => $this->text('HTML Boyutu (bayt)', 'HTML Size (bytes)'),
            'host' => 'Host',
            'cms' => 'CMS',
            'site_key' => $this->text('Site Kimliği', 'Site Key'),
            'wordpress_version' => 'WordPress',
            'php_version' => 'PHP',
            'active_theme' => $this->text('Aktif Tema', 'Active Theme'),
            'site_health_good_count' => $this->text('Site Health İyi Gözlemleri', 'Site Health Good Observations'),
            'site_health_recommended_count' => $this->text('Site Health Önerilen Gözlemler', 'Site Health Recommended Observations'),
            'site_health_critical_count' => $this->text('Site Health Kritik Gözlemler', 'Site Health Critical Observations'),
            'extension_type' => $this->text('Bileşen Türü', 'Extension Type'),
            'extension_id' => $this->text('Bileşen Kimliği', 'Extension ID'),
            'update_available' => $this->text('Güncelleme Var', 'Update Available'),
            'available_version' => $this->text('Yeni Sürüm', 'Available Version'),
            'taxonomy' => $this->text('Taksonomi', 'Taxonomy'),
            'term_id' => $this->text('Terim Kimliği', 'Term ID'),
            'seo_provider' => $this->text('SEO Sağlayıcısı', 'SEO Provider'),
            'seo_title' => $this->text('SEO Başlığı', 'SEO Title'),
            'object_type' => $this->text('İçerik Türü', 'Object Type'),
            'object_id' => $this->text('İçerik Kimliği', 'Object ID'),
            'status' => $this->text('Durum', 'Status'),
            'slug' => 'Slug',
            'published_at' => $this->text('Yayın Tarihi', 'Published At'),
            'modified_at' => $this->text('Güncellenme Tarihi', 'Modified At'),
            'template' => $this->text('Şablon', 'Template'),
            'strategy' => $this->text('Test Stratejisi', 'Test Strategy'),
            'lcp_ms' => 'LCP (ms)',
            default => str($field)->replace('_', ' ')->title()->toString(),
        };
    }

    private function familyLabel(string $family): string
    {
        return match ($family) {
            WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL => $this->text('Site Taraması', 'Site Crawl'),
            WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS => $this->text('Teknik HTML Kontrolü', 'Technical HTML Check'),
            WebsiteRequestFamilyCatalog::FAMILY_DNS_TLS => $this->text('SSL/TLS Kontrolü', 'SSL/TLS Check'),
            WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED => 'PageSpeed',
            WebsiteRequestFamilyCatalog::FAMILY_WP_REST => $this->text('WordPress Bağlayıcısı', 'WordPress Connector'),
            default => $family,
        };
    }

    private function datasetStatusLabel(string $state): string
    {
        return match ($state) {
            'completed' => $this->text('Tamamlandı', 'Completed'),
            'partial' => $this->text('Kısmi', 'Partial'),
            'failed' => $this->text('Başarısız', 'Failed'),
            'running' => $this->text('Devam ediyor', 'In progress'),
            'connection_required' => $this->text('Bağlantı gerekli', 'Connection required'),
            'needs_setup' => $this->text('Kurulum gerekli', 'Setup required'),
            'not_available' => $this->text('Henüz devrede değil', 'Not active yet'),
            'not_eligible' => $this->text('Uygun değil', 'Not eligible'),
            'skipped' => $this->text('Atlandı', 'Skipped'),
            default => $this->text('Henüz veri yok', 'No data yet'),
        };
    }

    private function sourceGroupStatusLabel(string $state): string
    {
        return match ($state) {
            'completed' => $this->text('Tüm datasetler hazır', 'All datasets ready'),
            'running' => $this->text('Veri çekimi devam ediyor', 'Collection in progress'),
            'partial' => $this->text('Kısmi kapsam', 'Partial coverage'),
            'attention' => $this->text('Eksik kaynak var', 'Missing source'),
            'connection_required' => $this->text('Bağlantı gerekli', 'Connection required'),
            'not_applicable' => $this->text('Uygulanamaz', 'Not applicable'),
            'not_run' => $this->text('Henüz veri çekilmedi', 'Never collected'),
            default => $this->text('Durum bekleniyor', 'Status pending'),
        };
    }

    private function sourceTone(string $state): string
    {
        return match ($state) {
            'completed' => 'success',
            'running' => 'info',
            'partial', 'connection_required', 'needs_setup', 'attention' => 'warning',
            'failed' => 'error',
            default => 'neutral',
        };
    }

    private function overallState(?CollectionRun $run, bool $collectable): string
    {
        if (! $collectable) {
            return 'needs_setup';
        }

        return match ($run?->status?->value) {
            'completed' => 'completed',
            'partial' => 'partial',
            'failed', 'cancelled' => 'attention',
            'queued', 'running', 'retrying', 'cancellation_requested' => 'running',
            null => 'never_collected',
            default => 'neutral',
        };
    }

    private function runStatusLabel(CollectionRun $run): string
    {
        return match ($run->status?->value) {
            'completed' => $this->text('Başarılı', 'Successful'),
            'partial' => $this->text('Kısmi tamamlandı', 'Partially completed'),
            'failed' => $this->text('Başarısız', 'Failed'),
            'cancelled' => $this->text('İptal edildi', 'Cancelled'),
            'cancellation_requested' => $this->text('İptal ediliyor', 'Cancelling'),
            'queued' => $this->text('Kuyrukta', 'Queued'),
            'running' => $this->text('Devam ediyor', 'In progress'),
            'retrying' => $this->text('Yeniden deneniyor', 'Retrying'),
            'skipped' => $this->text('Atlandı', 'Skipped'),
            'not_eligible' => $this->text('Uygun değil', 'Not eligible'),
            default => $this->text('Bilinmiyor', 'Unknown'),
        };
    }

    /** @param Collection<int, array<string, mixed>> $rows @return Collection<int, array<string, mixed>> */
    private function filterRows(Collection $rows): Collection
    {
        $query = mb_strtolower(trim($this->search));
        if ($query !== '') {
            $rows = $rows->filter(function (array $row) use ($query): bool {
                /** @var DigitalAsset $asset */
                $asset = $row['asset'];
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $asset->name,
                    $asset->primary_url,
                    $asset->domain,
                    $asset->cms,
                    $asset->brand?->name,
                    $asset->brand?->customer?->name,
                ])));

                return str_contains($haystack, $query);
            });
        }

        $rows = match ($this->filter) {
            'completed' => $rows->filter(fn (array $row): bool => ($row['run']?->status?->value ?? null) === 'completed'),
            'attention' => $rows->filter(fn (array $row): bool => in_array($row['overall_state'], ['attention', 'partial', 'needs_setup'], true)),
            'never' => $rows->whereNull('run'),
            'wordpress' => $rows->where('wordpress_detected', true),
            default => $rows,
        };

        return $rows->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows @return list<array{key: string, label: string, count: int}> */
    private function filterOptions(Collection $rows): array
    {
        return [
            ['key' => 'all', 'label' => $this->text('Tümü', 'All'), 'count' => $rows->count()],
            ['key' => 'completed', 'label' => $this->text('Son Çekim Başarılı', 'Latest Run Successful'), 'count' => $rows->filter(fn (array $row): bool => ($row['run']?->status?->value ?? null) === 'completed')->count()],
            ['key' => 'attention', 'label' => $this->text('Dikkat Gereken', 'Needs Attention'), 'count' => $rows->filter(fn (array $row): bool => in_array($row['overall_state'], ['attention', 'partial', 'needs_setup'], true))->count()],
            ['key' => 'never', 'label' => $this->text('Henüz Çekilmedi', 'Never Collected'), 'count' => $rows->whereNull('run')->count()],
            ['key' => 'wordpress', 'label' => 'WordPress', 'count' => $rows->where('wordpress_detected', true)->count()],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, mixed>|null */
    private function selectedRow(Collection $rows): ?array
    {
        if ($rows->isEmpty() || $this->selectedAssetId === null) {
            return null;
        }
        $selected = $rows->first(fn (array $row): bool => (int) $row['asset']->id === $this->selectedAssetId);

        return is_array($selected) ? $selected : null;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function collectionHistory(int $assetId): Collection
    {
        return CollectionRun::query()
            ->with(['datasetRuns', 'requestedBy'])
            ->where('digital_asset_id', $assetId)
            ->latest('id')
            ->limit(20)
            ->get()
            ->filter(fn (CollectionRun $run): bool => $this->isWebsiteRun($run))
            ->take(10)
            ->map(fn (CollectionRun $run): array => [
                'id' => $run->id,
                'status' => $run->status?->value,
                'status_label' => $this->runStatusLabel($run),
                'datasets_completed' => (int) $run->datasets_completed,
                'datasets_total' => (int) $run->datasets_total,
                'datasets_failed' => (int) $run->datasets_failed,
                'rows_written' => (int) $run->datasetRuns->sum('rows_written'),
                'trigger_label' => $this->triggerLabel($run->trigger_type?->value),
                'requested_by' => $run->requestedBy?->name ?? $this->text('Sistem', 'System'),
                'started_at' => $run->started_at ?? $run->created_at,
                'finished_at' => $run->finished_at,
                'duration_label' => ($run->started_at ?? $run->created_at)?->diffForHumans($run->finished_at ?? $run->updated_at, true),
                'failure_summary' => $run->failure_summary,
                'updated_at' => $run->updated_at,
            ])->values();
    }

    /** @return array<string, mixed>|null */
    private function liveConsole(?CollectionRun $run): ?array
    {
        if (! $run instanceof CollectionRun) {
            return null;
        }

        $active = in_array($run->status?->value, ['queued', 'running', 'retrying', 'cancellation_requested'], true);
        $stages = $run->datasetRuns
            ->groupBy('request_family_id')
            ->map(function (Collection $datasetRuns, string $family): array {
                $states = $datasetRuns->map(fn (CollectionDatasetRun $datasetRun): string => $this->datasetRunState($datasetRun));
                $state = match (true) {
                    $states->contains('running') => 'running',
                    $states->every(fn (string $candidate): bool => $candidate === 'completed') => 'completed',
                    $states->contains('completed') || $states->contains('partial') => 'partial',
                    $states->contains('failed') => 'failed',
                    $states->contains('skipped') || $states->contains('not_eligible') => 'skipped',
                    default => 'not_run',
                };
                $progressTotal = (int) $datasetRuns->sum(fn (CollectionDatasetRun $datasetRun): int => max(0, (int) $datasetRun->progress_total));
                $progressCurrent = (int) $datasetRuns->sum(fn (CollectionDatasetRun $datasetRun): int => max(0, (int) $datasetRun->progress_current));
                $latestStage = $datasetRuns->sortByDesc('last_activity_at')->first()?->stage;

                return [
                    'family' => $family,
                    'label' => $this->familyLabel($family),
                    'optional' => $family === WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED,
                    'state' => $state,
                    'status_label' => $this->datasetStatusLabel($state),
                    'datasets_completed' => $states->filter(fn (string $candidate): bool => $candidate === 'completed')->count(),
                    'datasets_total' => $states->count(),
                    'rows_received' => (int) $datasetRuns->sum('rows_received'),
                    'rows_written' => (int) $datasetRuns->sum('rows_written'),
                    'progress_current' => $progressCurrent,
                    'progress_total' => $progressTotal,
                    'progress_percent' => $progressTotal > 0 ? min(100, (int) round(($progressCurrent / $progressTotal) * 100)) : null,
                    'stage' => is_string($latestStage) && $latestStage !== '' ? $latestStage : null,
                    'error' => $datasetRuns->first(fn (CollectionDatasetRun $datasetRun): bool => filled($datasetRun->error_message))?->error_message,
                ];
            })->values();

        $requiredDatasetRuns = $run->datasetRuns->reject(
            fn (CollectionDatasetRun $datasetRun): bool => $datasetRun->request_family_id === WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED,
        );
        $datasetsTotal = $requiredDatasetRuns->count();
        $datasetsCompleted = $requiredDatasetRuns->filter(
            fn (CollectionDatasetRun $datasetRun): bool => $datasetRun->status?->value === 'completed',
        )->count();

        return [
            'id' => $run->id,
            'active' => $active,
            'state' => $this->overallState($run, true),
            'status_label' => $this->runStatusLabel($run),
            'datasets_completed' => $datasetsCompleted,
            'datasets_total' => $datasetsTotal,
            'datasets_failed' => $requiredDatasetRuns->filter(
                fn (CollectionDatasetRun $datasetRun): bool => in_array($datasetRun->status?->value, ['failed', 'cancelled'], true),
            )->count(),
            'progress_percent' => $datasetsTotal > 0 ? min(100, (int) round(($datasetsCompleted / $datasetsTotal) * 100)) : 0,
            'rows_received' => (int) $run->datasetRuns->sum('rows_received'),
            'rows_written' => (int) $run->datasetRuns->sum('rows_written'),
            'duration_label' => ($run->started_at ?? $run->created_at)?->diffForHumans($run->finished_at ?? now(), true),
            'last_activity_at' => $run->last_activity_at ?? $run->updated_at,
            'stages' => $stages,
            'failure_summary' => $run->failure_summary,
        ];
    }

    private function triggerLabel(?string $trigger): string
    {
        return match ($trigger) {
            'manual' => $this->text('Manuel', 'Manual'),
            'initial_backfill' => $this->text('İlk veri çekimi', 'Initial backfill'),
            'incremental' => $this->text('Planlı güncelleme', 'Scheduled refresh'),
            'retry' => $this->text('Yeniden deneme', 'Retry'),
            'replay' => $this->text('Tekrar oynatma', 'Replay'),
            default => $this->text('Sistem', 'System'),
        };
    }

    private function isWebsiteRun(CollectionRun $run): bool
    {
        return in_array('WEBSITE_DIRECT', (array) data_get($run->request_context, 'provider_sources', []), true);
    }

    private function text(string $tr, string $en): string
    {
        return app()->getLocale() === 'tr' ? $tr : $en;
    }
}
