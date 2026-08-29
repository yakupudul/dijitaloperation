<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\PageSpeedConnectionProbeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Web Sitesi Veri Toplama')]
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
            $this->message = app()->getLocale() === 'tr'
                ? "{$asset->name} için veri çekimi kuyruğa alındı. Çekim #{$run->id}."
                : "Website collection queued for {$asset->name}. Run #{$run->id}.";
        } catch (Throwable $exception) {
            report($exception);
            $this->messageTone = 'error';
            $this->message = app()->getLocale() === 'tr'
                ? 'Web sitesi veri çekimi başlatılamadı.'
                : 'Website collection could not be started.';
        }
    }

    public function render(): View
    {
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
            $pageSpeed = $asset->connections->first(
                fn (CoreConnection $connection): bool => $connection->type === PageSpeedConnectionProbeService::CONNECTION_TYPE,
            );
            $credentialPayload = $pageSpeed?->credential?->encrypted_payload;
            $pageSpeedReady = $pageSpeed instanceof CoreConnection
                && $pageSpeed->enabled
                && is_array($credentialPayload)
                && filled($credentialPayload['api_key'] ?? null);

            /** @var CollectionRun|null $run */
            $run = $runs->get($asset->id);
            $collectable = filled($asset->primary_url) || filled($asset->domain);
            $wordpressDetected = str_contains(strtolower((string) $asset->cms), 'wordpress');
            $sources = $this->sourceSummaries($run, $collectable, $pageSpeedReady);
            $completedSources = collect($sources)->where('state', 'completed')->count();
            $attentionSources = collect($sources)->filter(
                fn (array $source): bool => in_array($source['state'], ['failed', 'partial', 'connection_required'], true),
            )->count();

            return [
                'asset' => $asset,
                'run' => $run,
                'collectable' => $collectable,
                'page_speed_ready' => $pageSpeedReady,
                'wordpress_detected' => $wordpressDetected,
                'sources' => $sources,
                'completed_sources' => $completedSources,
                'source_total' => count($sources),
                'attention_sources' => $attentionSources,
                'rows_written' => collect($sources)->sum('rows_written'),
                'pages_completed' => collect($sources)->sum('pages_completed'),
                'overall_state' => $this->overallState($run, $collectable),
                'status_label' => $run ? $this->runStatusLabel($run) : $this->text('Henüz veri çekilmedi', 'Never collected'),
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

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceSummaries(?CollectionRun $run, bool $collectable, bool $pageSpeedReady): array
    {
        $definitions = [
            [
                'key' => 'crawl',
                'family' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
                'label' => $this->text('Site Taraması', 'Site Crawl'),
                'description' => $this->text('Site içindeki sayfaları ve bağlantıları tarar.', 'Crawls pages and links within the site.'),
            ],
            [
                'key' => 'html',
                'family' => WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS,
                'label' => $this->text('Teknik HTML Kontrolü', 'Technical HTML Check'),
                'description' => $this->text('Ana sayfa, robots.txt ve sitemap.xml teknik sinyallerini kontrol eder.', 'Checks homepage, robots.txt and sitemap.xml technical signals.'),
            ],
            [
                'key' => 'tls',
                'family' => WebsiteRequestFamilyCatalog::FAMILY_DNS_TLS,
                'label' => $this->text('SSL/TLS Altyapısı', 'SSL/TLS Infrastructure'),
                'description' => $this->text('Sertifika ve altyapı anlık görüntüsünü toplar.', 'Collects certificate and infrastructure snapshots.'),
            ],
            [
                'key' => 'pagespeed',
                'family' => WebsiteRequestFamilyCatalog::FAMILY_PAGESPEED,
                'label' => $this->text('PageSpeed Performansı', 'PageSpeed Performance'),
                'description' => $this->text('Google Lighthouse performans ölçümlerini toplar.', 'Collects Google Lighthouse performance measurements.'),
            ],
        ];

        $datasetRuns = $run?->datasetRuns ?? collect();

        return array_map(function (array $definition) use ($datasetRuns, $collectable, $pageSpeedReady): array {
            /** @var CollectionDatasetRun|null $datasetRun */
            $datasetRun = $datasetRuns->first(
                fn (CollectionDatasetRun $candidate): bool => $candidate->request_family_id === $definition['family'],
            );

            $state = $this->sourceState(
                key: (string) $definition['key'],
                datasetRun: $datasetRun,
                collectable: $collectable,
                pageSpeedReady: $pageSpeedReady,
            );

            return array_merge($definition, [
                'dataset_run' => $datasetRun,
                'state' => $state,
                'status_label' => $this->sourceStatusLabel($state),
                'tone' => $this->sourceTone($state),
                'rows_written' => max(0, (int) ($datasetRun?->rows_written ?? 0)),
                'rows_received' => max(0, (int) ($datasetRun?->rows_received ?? 0)),
                'pages_completed' => max(0, (int) ($datasetRun?->pages_completed ?? 0)),
                'progress_current' => $datasetRun?->progress_current,
                'progress_total' => $datasetRun?->progress_total,
                'result_detail' => $this->sourceResultDetail($state, $datasetRun),
                'error_code' => $datasetRun?->error_code,
            ]);
        }, $definitions);
    }

    private function sourceState(
        string $key,
        ?CollectionDatasetRun $datasetRun,
        bool $collectable,
        bool $pageSpeedReady,
    ): string {
        if (! $collectable && $key !== 'pagespeed') {
            return 'needs_setup';
        }

        if ($key === 'pagespeed' && ! $pageSpeedReady) {
            return 'connection_required';
        }

        if (! $datasetRun instanceof CollectionDatasetRun) {
            return 'not_run';
        }

        return match ($datasetRun->status?->value) {
            'completed' => 'completed',
            'partial' => 'partial',
            'failed', 'cancelled' => 'failed',
            'queued', 'running', 'retrying', 'cancellation_requested' => 'running',
            'skipped' => 'skipped',
            'not_eligible' => 'not_eligible',
            default => 'not_run',
        };
    }

    private function sourceStatusLabel(string $state): string
    {
        return match ($state) {
            'completed' => $this->text('Tamamlandı', 'Completed'),
            'partial' => $this->text('Kısmi tamamlandı', 'Partially completed'),
            'failed' => $this->text('Başarısız', 'Failed'),
            'running' => $this->text('Çalışıyor', 'Running'),
            'connection_required' => $this->text('Bağlantı gerekli', 'Connection required'),
            'needs_setup' => $this->text('URL/domain gerekli', 'URL/domain required'),
            'skipped' => $this->text('Atlandı', 'Skipped'),
            'not_eligible' => $this->text('Uygun değil', 'Not eligible'),
            default => $this->text('Henüz çalıştırılmadı', 'Not run yet'),
        };
    }

    private function sourceTone(string $state): string
    {
        return match ($state) {
            'completed' => 'success',
            'running' => 'info',
            'partial', 'connection_required', 'needs_setup' => 'warning',
            'failed' => 'error',
            default => 'neutral',
        };
    }

    private function sourceResultDetail(string $state, ?CollectionDatasetRun $datasetRun): string
    {
        if ($state === 'connection_required') {
            return $this->text('PageSpeed API bağlantısını tamamlayın.', 'Configure the PageSpeed API connection.');
        }

        if ($state === 'needs_setup') {
            return $this->text('Web sitesi varlığına ana URL veya domain ekleyin.', 'Add a primary URL or domain to the website asset.');
        }

        if ($state === 'not_run') {
            return $this->text('Bu kaynak için henüz veri çekimi yapılmadı.', 'No collection has run for this source yet.');
        }

        if ($state === 'running') {
            return $this->text('Veri çekimi devam ediyor.', 'Collection is in progress.');
        }

        if ($state === 'completed') {
            $parts = [];
            $rows = max(0, (int) ($datasetRun?->rows_written ?? 0));
            $pages = max(0, (int) ($datasetRun?->pages_completed ?? 0));

            if ($rows > 0) {
                $parts[] = $this->text("{$rows} kayıt yazıldı", "{$rows} rows written");
            }
            if ($pages > 0) {
                $parts[] = $this->text("{$pages} sayfa/işlem tamamlandı", "{$pages} pages/steps completed");
            }

            return $parts !== []
                ? implode(' · ', $parts)
                : $this->text('Kaynak başarıyla tamamlandı.', 'Source completed successfully.');
        }

        if ($state === 'partial') {
            return $this->text('Kaynağın bir bölümü tamamlandı; kalan işler veya hatalar bulunuyor.', 'Part of the source completed; remaining work or errors exist.');
        }

        if ($state === 'failed') {
            return $this->translatedDatasetError($datasetRun);
        }

        if ($state === 'skipped') {
            return $this->text('Bu çekimde kaynak atlandı.', 'Source was skipped in this run.');
        }

        if ($state === 'not_eligible') {
            return $this->text('Bu web sitesi mevcut koşullarda bu kaynak için uygun değil.', 'This website is not eligible for this source under current conditions.');
        }

        return '—';
    }

    private function translatedDatasetError(?CollectionDatasetRun $datasetRun): string
    {
        $code = strtoupper((string) ($datasetRun?->error_code ?? ''));

        return match ($code) {
            'PAGESPEED_CONNECTION_REQUIRED' => $this->text('PageSpeed API bağlantısı yapılmamış.', 'PageSpeed API connection is missing.'),
            'PAGESPEED_KEY_MISSING' => $this->text('PageSpeed API anahtarı eksik.', 'PageSpeed API key is missing.'),
            'PAGESPEED_HTTP' => $this->text('PageSpeed servisi isteği tamamlayamadı.', 'The PageSpeed service could not complete the request.'),
            'UNIMPLEMENTED_CAPABILITY' => $this->text('Bu veri kaynağı henüz uygulamada devrede değil.', 'This data source is not active in the application yet.'),
            default => app()->getLocale() === 'tr'
                ? ($code !== '' ? "Kaynak çalıştırılamadı. Hata kodu: {$code}" : 'Kaynak çalıştırılamadı.')
                : ((string) ($datasetRun?->error_message ?: 'The source could not be collected.')),
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
            'completed' => $this->text('Tamamlandı', 'Completed'),
            'partial' => $this->text('Kısmi', 'Partial'),
            'failed' => $this->text('Başarısız', 'Failed'),
            'cancelled' => $this->text('İptal edildi', 'Cancelled'),
            'cancellation_requested' => $this->text('İptal ediliyor', 'Cancelling'),
            'queued' => $this->text('Kuyrukta', 'Queued'),
            'running' => $this->text('Çalışıyor', 'Running'),
            'retrying' => $this->text('Yeniden deneniyor', 'Retrying'),
            'skipped' => $this->text('Atlandı', 'Skipped'),
            'not_eligible' => $this->text('Uygun değil', 'Not eligible'),
            default => $this->text('Bilinmiyor', 'Unknown'),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
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

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, count: int}>
     */
    private function filterOptions(Collection $rows): array
    {
        return [
            ['key' => 'all', 'label' => $this->text('Tümü', 'All'), 'count' => $rows->count()],
            ['key' => 'completed', 'label' => $this->text('Başarılı', 'Completed'), 'count' => $rows->filter(fn (array $row): bool => ($row['run']?->status?->value ?? null) === 'completed')->count()],
            ['key' => 'attention', 'label' => $this->text('Dikkat Gereken', 'Needs Attention'), 'count' => $rows->filter(fn (array $row): bool => in_array($row['overall_state'], ['attention', 'partial', 'needs_setup'], true))->count()],
            ['key' => 'never', 'label' => $this->text('Henüz Çekilmedi', 'Never Collected'), 'count' => $rows->whereNull('run')->count()],
            ['key' => 'wordpress', 'label' => 'WordPress', 'count' => $rows->where('wordpress_detected', true)->count()],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function selectedRow(Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        if ($this->selectedAssetId !== null) {
            $selected = $rows->first(
                fn (array $row): bool => (int) $row['asset']->id === $this->selectedAssetId,
            );
            if (is_array($selected)) {
                return $selected;
            }
        }

        $first = $rows->first();

        return is_array($first) ? $first : null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
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
            ])
            ->values();
    }

    private function isWebsiteRun(CollectionRun $run): bool
    {
        return in_array(
            'WEBSITE_DIRECT',
            (array) data_get($run->request_context, 'provider_sources', []),
            true,
        );
    }

    private function text(string $tr, string $en): string
    {
        return app()->getLocale() === 'tr' ? $tr : $en;
    }
}
