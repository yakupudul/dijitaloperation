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

    public function selectWebsite(int $assetId): void
    {
        $this->selectedAssetId = $assetId;
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
            $wordpressDetected = str_contains(strtolower((string) $asset->cms), 'wordpress');
            $collectors = $this->collectorSummaries($run, $collectable, $pageSpeedReady);
            $completedCollectors = collect($collectors)->where('state', 'completed')->count();
            $collectorTotal = count($collectors);

            return [
                'asset' => $asset,
                'run' => $run,
                'collectable' => $collectable,
                'page_speed_ready' => $pageSpeedReady,
                'wordpress_detected' => $wordpressDetected,
                'collectors' => $collectors,
                'completed_collectors' => $completedCollectors,
                'collector_total' => $collectorTotal,
                'coverage_percent' => $collectorTotal > 0 ? (int) round(($completedCollectors / $collectorTotal) * 100) : 0,
                'missing_collectors' => collect($collectors)->whereNotIn('state', ['completed', 'running'])->count(),
                'overall_state' => $this->overallState($run, $collectable),
                'run_status_label' => $run ? $this->runStatusLabel($run) : $this->text('Henüz veri çekilmedi', 'Never collected'),
                'last_run_at' => $run?->updated_at,
            ];
        });

        $stats = [
            'total' => $allRows->count(),
            'collect_ready' => $allRows->where('collectable', true)->count(),
            'completed' => $allRows->filter(fn (array $row): bool => ($row['run']?->status?->value ?? null) === 'completed')->count(),
            'attention' => $allRows->filter(fn (array $row): bool => in_array($row['overall_state'], ['attention', 'partial', 'needs_setup'], true))->count(),
            'never_collected' => $allRows->whereNull('run')->count(),
        ];

        $rows = $this->filterRows($allRows);
        $selectedRow = $this->selectedRow($rows);

        if ($selectedRow !== null) {
            $selectedRow = $this->enrichSelectedRow($selectedRow, $storageRegistry);
        }

        $history = $selectedRow !== null
            ? $this->collectionHistory((int) $selectedRow['asset']->id)
            : collect();

        return view('livewire.operator.integrations.website-integration-index', [
            'rows' => $rows,
            'selectedRow' => $selectedRow,
            'history' => $history,
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
    private function collectorSummaries(?CollectionRun $run, bool $collectable, bool $pageSpeedReady): array
    {
        $definitions = [
            ['key' => 'crawl', 'family' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL],
            ['key' => 'html', 'family' => WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS],
            ['key' => 'tls', 'family' => WebsiteRequestFamilyCatalog::FAMILY_DNS_TLS],
            ['key' => 'pagespeed', 'family' => WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED],
        ];
        $datasetRuns = $run?->datasetRuns ?? collect();

        return array_map(function (array $definition) use ($datasetRuns, $collectable, $pageSpeedReady): array {
            /** @var CollectionDatasetRun|null $datasetRun */
            $datasetRun = $datasetRuns->first(
                fn (CollectionDatasetRun $candidate): bool => $candidate->request_family_id === $definition['family'],
            );

            return [
                'key' => $definition['key'],
                'family' => $definition['family'],
                'dataset_run' => $datasetRun,
                'state' => $this->collectorState((string) $definition['key'], $datasetRun, $collectable, $pageSpeedReady),
            ];
        }, $definitions);
    }

    private function collectorState(string $key, ?CollectionDatasetRun $datasetRun, bool $collectable, bool $pageSpeedReady): string
    {
        if (! $collectable && $key !== 'pagespeed') {
            return 'needs_setup';
        }
        if ($key === 'pagespeed' && ! $pageSpeedReady) {
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
        $datasetRunIds = $run?->datasetRuns?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];

        $batches = $datasetRunIds === []
            ? collect()
            : DatasetWriteBatch::query()
                ->whereIn('dataset_run_id', $datasetRunIds)
                ->whereIn('dataset_id', $datasetIds)
                ->orderBy('id')
                ->get()
                ->groupBy('dataset_id');

        $materializations = DatasetMaterialization::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('dataset_id', array_values(array_unique(array_merge($datasetIds, ['website_cms_object_snapshot']))))
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

        $publicCompleted = $publicDatasets->where('state', 'completed')->count();
        $publicRunning = $publicDatasets->where('state', 'running')->count();
        $publicAttention = $publicDatasets->filter(
            fn (array $dataset): bool => in_array($dataset['state'], ['partial', 'failed', 'connection_required', 'needs_setup'], true),
        )->count();
        $publicTotal = $publicDatasets->count();
        $publicState = match (true) {
            $publicTotal > 0 && $publicCompleted === $publicTotal => 'completed',
            $publicRunning > 0 => 'running',
            $publicAttention > 0 && $publicCompleted > 0 => 'partial',
            $publicAttention > 0 => 'attention',
            $run === null => 'not_run',
            default => 'neutral',
        };

        $connectorDatasets = collect();
        if ((bool) $row['wordpress_detected']) {
            $connectorDatasets->push($this->connectorDatasetSummary(
                asset: $asset,
                materialization: $materializations->get('website_cms_object_snapshot'),
                storageRegistry: $storageRegistry,
            ));
        }

        $row['data_sources'] = [
            [
                'key' => 'google',
                'label' => $this->text('Google Verileri', 'Google Data'),
                'description' => $this->text('GA4 ve Search Console bağlantıları mevcut Google entegrasyon alanından yönetilir.', 'GA4 and Search Console connections are managed from the existing Google integration area.'),
                'state' => 'managed_elsewhere',
                'status_label' => $this->text('Ayrı entegrasyondan yönetilir', 'Managed separately'),
                'connection_label' => 'GA4 + Search Console',
                'datasets' => collect(),
            ],
            [
                'key' => 'public_web',
                'label' => $this->text('Genel Web Verileri', 'Public Web Data'),
                'description' => $this->text('Eklenti veya hesap bağlantısı olmadan yalnızca web sitesi adresinden toplanabilen teknik ve içerik verileri.', 'Technical and content data collected from the website address without a plugin or account connection.'),
                'state' => $publicState,
                'status_label' => $this->sourceGroupStatusLabel($publicState),
                'connection_label' => $this->text('Bağlantı gerektirmez', 'No connection required'),
                'datasets' => $publicDatasets,
                'completed' => $publicCompleted,
                'total' => $publicTotal,
                'coverage_percent' => $publicTotal > 0 ? (int) round(($publicCompleted / $publicTotal) * 100) : 0,
            ],
            [
                'key' => 'site_connector',
                'label' => $this->text('Web Sitesi Bağlayıcısı', 'Site Connector'),
                'description' => $this->text('Web sitesine kurulan bağlayıcı üzerinden public taraftan görülemeyen CMS envanterini toplar.', 'Collects CMS inventory that is not observable publicly through a connector installed on the website.'),
                'state' => (bool) $row['wordpress_detected'] ? 'not_available' : 'not_applicable',
                'status_label' => (bool) $row['wordpress_detected'] ? $this->text('Henüz devrede değil', 'Not active yet') : $this->text('Uygun bağlayıcı yok', 'No applicable connector'),
                'connection_label' => (bool) $row['wordpress_detected'] ? $this->text('WordPress algılandı', 'WordPress detected') : $this->text('CMS bağlayıcısı bekleniyor', 'CMS connector pending'),
                'datasets' => $connectorDatasets,
            ],
        ];

        $row['public_dataset_completed'] = $publicCompleted;
        $row['public_dataset_total'] = $publicTotal;
        $row['public_dataset_coverage_percent'] = $publicTotal > 0 ? (int) round(($publicCompleted / $publicTotal) * 100) : 0;
        $row['current_rows'] = $publicDatasets->sum(fn (array $dataset): int => (int) ($dataset['current_rows'] ?? 0));

        return $row;
    }

    /** @return list<string> */
    private function publicDatasetIds(): array
    {
        $ids = [];
        foreach (WebsiteRequestFamilyCatalog::supportedFamilies() as $family) {
            foreach ((array) WebsiteRequestFamilyCatalog::definition($family)['dataset_ids'] as $datasetId) {
                $ids[] = (string) $datasetId;
            }
        }

        return array_values(array_unique($ids));
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
        $state = $this->datasetState(
            $datasetId,
            $familyRuns,
            $pageSpeedReady,
            filled($asset->primary_url) || filled($asset->domain),
        );
        $processedRows = $batches->sum(fn (DatasetWriteBatch $batch): int => max(0, (int) $batch->rows_received));
        $successfulBatches = $batches->filter(fn (DatasetWriteBatch $batch): bool => ($batch->status?->value ?? null) === 'committed')->count();
        $failedBatches = $batches->filter(fn (DatasetWriteBatch $batch): bool => ($batch->status?->value ?? null) === 'failed')->count();
        $currentRows = max(0, (int) ($materialization?->row_count_approx ?? 0));
        $schema = $this->datasetSchema($datasetId, $storageRegistry);

        return [
            'id' => $datasetId,
            'label' => $this->datasetLabel($datasetId),
            'description' => $this->datasetDescription($datasetId),
            'state' => $state,
            'status_label' => $this->datasetStatusLabel($state),
            'tone' => $this->sourceTone($state),
            'current_rows' => $currentRows,
            'processed_rows' => $processedRows,
            'successful_batches' => $successfulBatches,
            'failed_batches' => $failedBatches,
            'last_collected_at' => $materialization?->last_collected_at,
            'families' => $families,
            'collectors' => array_values(array_map(fn (string $family): string => $this->familyLabel($family), $families)),
            'fields' => $schema['fields'],
            'system_field_count' => $schema['system_field_count'],
            'table' => $schema['table'],
            'preview' => $this->datasetPreview($asset->id, $run?->id, $schema),
            'result_detail' => $this->datasetResultDetail($state, $currentRows, $processedRows),
        ];
    }

    /** @return array<string, mixed> */
    private function connectorDatasetSummary(DigitalAsset $asset, mixed $materialization, DataPoolStorageRegistry $storageRegistry): array
    {
        $datasetId = 'website_cms_object_snapshot';
        $schema = $this->datasetSchema($datasetId, $storageRegistry);

        return [
            'id' => $datasetId,
            'label' => $this->datasetLabel($datasetId),
            'description' => $this->datasetDescription($datasetId),
            'state' => 'not_available',
            'status_label' => $this->text('Henüz devrede değil', 'Not active yet'),
            'tone' => 'neutral',
            'current_rows' => max(0, (int) ($materialization?->row_count_approx ?? 0)),
            'processed_rows' => 0,
            'successful_batches' => 0,
            'failed_batches' => 0,
            'last_collected_at' => $materialization?->last_collected_at,
            'families' => [WebsiteRequestFamilyCatalog::FAMILY_WP_REST],
            'collectors' => [$this->text('WordPress Bağlayıcısı', 'WordPress Connector')],
            'fields' => $schema['fields'],
            'system_field_count' => $schema['system_field_count'],
            'table' => $schema['table'],
            'preview' => $this->datasetPreview($asset->id, null, $schema),
            'result_detail' => $this->text('Kimlik doğrulamalı WordPress bağlayıcısı production kullanıma açılmadığı için bu dataset henüz toplanmıyor.', 'This dataset is not collected yet because the authenticated WordPress connector is not production-ready.'),
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
        if ($states->contains('completed')) {
            return 'completed';
        }
        if ($states->contains('partial')) {
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

    /** @param array{table: ?string, fields: list<array<string, mixed>>, system_field_count: int} $schema @return array{state: string, columns: list<array{name: string, label: string}>, rows: list<array<string, string>>} */
    private function datasetPreview(int $assetId, ?int $runId, array $schema): array
    {
        $table = $schema['table'];
        if (! is_string($table) || $table === '') {
            return ['state' => 'unavailable', 'columns' => [], 'rows' => []];
        }

        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'digital_asset_id')) {
                return ['state' => 'unavailable', 'columns' => [], 'rows' => []];
            }

            $availableColumns = Schema::getColumnListing($table);
            $fields = collect($schema['fields'])
                ->filter(fn (array $field): bool => in_array($field['name'], $availableColumns, true))
                ->take(6)
                ->values();
            if ($fields->isEmpty()) {
                return ['state' => 'unavailable', 'columns' => [], 'rows' => []];
            }

            $columnNames = $fields->pluck('name')->all();
            $query = DB::table($table)->where('digital_asset_id', $assetId)->select($columnNames);
            if (in_array('last_collected_at', $availableColumns, true)) {
                $query->orderByDesc('last_collected_at');
            } elseif (in_array('observed_at', $availableColumns, true)) {
                $query->orderByDesc('observed_at');
            } elseif ($runId !== null && in_array('last_collection_run_id', $availableColumns, true)) {
                $query->orderByRaw('CASE WHEN last_collection_run_id = ? THEN 0 ELSE 1 END', [$runId]);
            }

            $rows = $query->limit(5)->get()->map(function ($record) use ($columnNames): array {
                $data = (array) $record;
                $normalized = [];
                foreach ($columnNames as $column) {
                    $normalized[$column] = $this->previewValue($data[$column] ?? null);
                }

                return $normalized;
            })->values()->all();

            return [
                'state' => $rows === [] ? 'empty' : 'available',
                'columns' => $fields->map(fn (array $field): array => ['name' => $field['name'], 'label' => $field['label']])->all(),
                'rows' => $rows,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['state' => 'unavailable', 'columns' => [], 'rows' => []];
        }
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
            'website_metadata_snapshot' => $this->text('Başlık ve Meta Verileri', 'Titles and Metadata'),
            'website_heading_snapshot' => $this->text('Başlık Hiyerarşisi', 'Heading Hierarchy'),
            'website_schema_snapshot' => $this->text('Yapısal Veri (Schema)', 'Structured Data (Schema)'),
            'website_content_stats' => $this->text('İçerik İstatistikleri', 'Content Statistics'),
            'website_link_edge' => $this->text('İç / Dış Bağlantılar', 'Internal / External Links'),
            'website_crawl_issue_snapshot' => $this->text('Tarama Sorunları', 'Crawl Issues'),
            'website_infra_snapshot' => $this->text('SSL/TLS Altyapısı', 'SSL/TLS Infrastructure'),
            'website_performance_measurement' => $this->text('PageSpeed Performansı', 'PageSpeed Performance'),
            'website_cms_object_snapshot' => $this->text('WordPress İçerik Envanteri', 'WordPress Content Inventory'),
            default => str($datasetId)->replace('_', ' ')->title()->toString(),
        };
    }

    private function datasetDescription(string $datasetId): string
    {
        return match ($datasetId) {
            'website_url' => $this->text('Keşfedilen ve normalize edilen web sayfası adresleri.', 'Discovered and normalized website URLs.'),
            'website_http_snapshot' => $this->text('HTTP yanıt kodu, yönlendirme ve erişilebilirlik gözlemleri.', 'HTTP response, redirect, and availability observations.'),
            'website_metadata_snapshot' => $this->text('Title, meta description, canonical, robots ve dil sinyalleri.', 'Title, meta description, canonical, robots, and language signals.'),
            'website_heading_snapshot' => $this->text('H1-H6 başlık yapısı ve sayfa başlık hiyerarşisi.', 'H1-H6 structure and page heading hierarchy.'),
            'website_schema_snapshot' => $this->text('Sayfadaki yapılandırılmış veri / schema gözlemleri.', 'Structured data / schema observations found on the page.'),
            'website_content_stats' => $this->text('Kelime, paragraf, görünür metin ve içerik yoğunluğu istatistikleri.', 'Word, paragraph, visible text, and content density statistics.'),
            'website_link_edge' => $this->text('Sayfalar arasındaki iç bağlantılar ve harici link ilişkileri.', 'Internal page links and external link relationships.'),
            'website_crawl_issue_snapshot' => $this->text('Tarama sırasında tespit edilen teknik ve SEO sorunları.', 'Technical and SEO issues detected during crawling.'),
            'website_infra_snapshot' => $this->text('Alan adı hostu, sertifika ve SSL/TLS altyapı gözlemleri.', 'Host, certificate, and SSL/TLS infrastructure observations.'),
            'website_performance_measurement' => $this->text('Google PageSpeed / Lighthouse performans ölçümleri.', 'Google PageSpeed / Lighthouse performance measurements.'),
            'website_cms_object_snapshot' => $this->text('WordPress sayfa, yazı ve diğer CMS nesnelerinin authenticated envanteri.', 'Authenticated inventory of WordPress pages, posts, and other CMS objects.'),
            default => $datasetId,
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'url', 'requested_url', 'normalized_url', 'source_url', 'target_url', 'normalized_target_url', 'permalink' => 'URL',
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
            'issue_code' => $this->text('Sorun Kodu', 'Issue Code'),
            'severity' => $this->text('Önem Düzeyi', 'Severity'),
            'message' => $this->text('Açıklama', 'Message'),
            'is_internal' => $this->text('İç Bağlantı mı?', 'Internal Link?'),
            'anchor_text' => $this->text('Bağlantı Metni', 'Anchor Text'),
            'nofollow' => 'Nofollow',
            'rel' => 'Rel',
            'observed_at' => $this->text('Gözlem Zamanı', 'Observed At'),
            'host' => 'Host',
            'cms' => 'CMS',
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
        if ($rows->isEmpty()) {
            return null;
        }
        if ($this->selectedAssetId !== null) {
            $selected = $rows->first(fn (array $row): bool => (int) $row['asset']->id === $this->selectedAssetId);
            if (is_array($selected)) {
                return $selected;
            }
        }

        $first = $rows->first();

        return is_array($first) ? $first : null;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function collectionHistory(int $assetId): Collection
    {
        return CollectionRun::query()
            ->where('digital_asset_id', $assetId)
            ->latest('id')
            ->limit(20)
            ->get()
            ->filter(fn (CollectionRun $run): bool => $this->isWebsiteRun($run))
            ->take(5)
            ->map(fn (CollectionRun $run): array => [
                'id' => $run->id,
                'status' => $run->status?->value,
                'status_label' => $this->runStatusLabel($run),
                'datasets_completed' => (int) $run->datasets_completed,
                'datasets_total' => (int) $run->datasets_total,
                'datasets_failed' => (int) $run->datasets_failed,
                'updated_at' => $run->updated_at,
            ])->values();
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
