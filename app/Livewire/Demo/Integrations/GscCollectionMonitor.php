<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Services\Collection\CancellationService;
use App\Services\Collection\SearchConsole\SearchConsoleCentralCollectionService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

class GscCollectionMonitor extends Component
{
    private const CENTRAL_INTENTS = [
        'gsc_central_initial',
        'gsc_central_update',
        'gsc_central_repair',
        'gsc_central_resume',
        'gsc_central_smart',
    ];

    public ?string $actionMessage = null;

    public function stopRun(int $runId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();
        $run = $this->scopedRun($runId, $integration);

        if ($run->status->isTerminal()) {
            $this->actionMessage = 'Bu Search Console aktarımı zaten tamamlanmış.';

            return;
        }

        $cancellation->requestCancellation($run);
        $this->actionMessage = "Run #{$run->id} için durdurma istendi. Çalışan istek güvenli noktada duracak.";
    }

    public function stopResource(int $resourceRunId, CancellationService $cancellation): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();

        $resourceRun = CollectionResourceRun::query()
            ->with(['collectionRun', 'externalResource'])
            ->findOrFail($resourceRunId);

        $run = $resourceRun->collectionRun;
        if (! $run instanceof CollectionRun
            || ! $this->runBelongsToIntegration($run, $integration)
            || (int) $resourceRun->externalResource?->integration_id !== (int) $integration->id
            || $resourceRun->provider_or_source !== 'SEARCH_CONSOLE') {
            abort(404);
        }

        if ($resourceRun->status->isTerminal()) {
            $this->actionMessage = 'Bu site için Search Console aktarımı zaten bitmiş.';

            return;
        }

        $name = $this->siteLabel((string) $resourceRun->externalResource?->external_id);
        $cancellation->requestResourceCancellation($resourceRun);
        $this->actionMessage = "{$name} için durdurma istendi. Diğer seçili siteler devam edecek.";
    }

    public function repairResource(int $externalResourceId, SearchConsoleCentralCollectionService $collector): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();
        $user = auth()->user();

        try {
            $run = $collector->startSmartUpdate($integration, [$externalResourceId], $user);
            $label = (string) data_get($run->metadata, 'collection_intent_label', 'Search Console veri onarımı');
            $this->actionMessage = "{$label} başlatıldı. Run #{$run->id}.";
        } catch (Throwable $e) {
            $this->actionMessage = 'Eksik Search Console verileri yeniden başlatılamadı: '.$e->getMessage();
        }
    }

    public function render(): View
    {
        $integration = $this->googleIntegration(false);
        if (! $integration instanceof CoreIntegration) {
            return view('livewire.demo.integrations.gsc-collection-monitor', [
                'runs' => [],
                'issues' => [],
                'hasActive' => false,
            ]);
        }

        $activeStatuses = [
            CollectionRunStatus::Queued->value,
            CollectionRunStatus::Running->value,
            CollectionRunStatus::Retrying->value,
            CollectionRunStatus::CancellationRequested->value,
        ];

        $runs = CollectionRun::query()
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->whereIn('metadata->collection_intent', self::CENTRAL_INTENTS)
            ->where('request_context->context->google_integration_id', (int) $integration->id)
            ->whereIn('status', $activeStatuses)
            ->with([
                'resourceRuns.externalResource',
                'resourceRuns.datasetRuns',
                'datasetRuns',
            ])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (CollectionRun $run): array => $this->mapRun($run))
            ->values()
            ->all();

        $issues = CollectionResourceRun::query()
            ->where('provider_or_source', 'SEARCH_CONSOLE')
            ->whereNull('digital_asset_id')
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->whereHas('externalResource', fn ($query) => $query->where('integration_id', (int) $integration->id))
            ->whereHas('collectionRun', fn ($query) => $query
                ->where('metadata->collection_scope', 'provider_resource_first')
                ->whereIn('metadata->collection_intent', self::CENTRAL_INTENTS)
                ->where('request_context->context->google_integration_id', (int) $integration->id))
            ->with(['externalResource', 'datasetRuns'])
            ->orderByDesc('id')
            ->limit(150)
            ->get()
            ->unique('external_resource_id')
            ->filter(fn (CollectionResourceRun $resource): bool => $resource->datasetRuns
                ->contains(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Failed))
            ->take(10)
            ->map(fn (CollectionResourceRun $resource): array => $this->mapIssue($resource))
            ->values()
            ->all();

        return view('livewire.demo.integrations.gsc-collection-monitor', [
            'runs' => $runs,
            'issues' => $issues,
            'hasActive' => $runs !== [],
        ]);
    }

    private function authorizeOperator(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }
    }

    private function googleIntegration(bool $abortWhenMissing = true): ?CoreIntegration
    {
        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->orderBy('id')
            ->first();

        if (! $integration instanceof CoreIntegration && $abortWhenMissing) {
            abort(404);
        }

        return $integration;
    }

    private function scopedRun(int $runId, CoreIntegration $integration): CollectionRun
    {
        $run = CollectionRun::query()->findOrFail($runId);
        if (! $this->runBelongsToIntegration($run, $integration)) {
            abort(404);
        }

        return $run;
    }

    private function runBelongsToIntegration(CollectionRun $run, CoreIntegration $integration): bool
    {
        return data_get($run->metadata, 'collection_scope') === 'provider_resource_first'
            && in_array(data_get($run->metadata, 'collection_intent'), self::CENTRAL_INTENTS, true)
            && (int) data_get($run->request_context, 'context.google_integration_id') === (int) $integration->id;
    }

    /** @return array<string, mixed> */
    private function mapRun(CollectionRun $run): array
    {
        $datasets = $run->datasetRuns;
        $datasetTotal = $datasets->count();
        $finishedDatasets = $datasets->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status->isTerminal())->count();
        $completedDatasets = $datasets->where('status', CollectionRunStatus::Completed)->count();
        $failedDatasets = $datasets->where('status', CollectionRunStatus::Failed)->count();
        $cancelledDatasets = $datasets->where('status', CollectionRunStatus::Cancelled)->count();
        $progress = $datasetTotal > 0
            ? round($datasets->sum(fn (CollectionDatasetRun $dataset): float => $this->datasetProgress($dataset)) / $datasetTotal * 100, 1)
            : 0.0;

        $ranges = $datasets
            ->map(fn (CollectionDatasetRun $dataset) => data_get($dataset->metadata, 'date_range'))
            ->filter(fn ($range): bool => is_array($range) && isset($range['start'], $range['end']));
        $coverageStart = $ranges->pluck('start')->filter()->sort()->first();
        $coverageEnd = $ranges->pluck('end')->filter()->sortDesc()->first();

        $resources = $run->resourceRuns
            ->map(fn (CollectionResourceRun $resource): array => $this->mapResource($resource))
            ->values()
            ->all();

        return [
            'id' => (int) $run->id,
            'label' => (string) (data_get($run->metadata, 'collection_intent_label') ?: 'Search Console Merkezi Veri Toplama'),
            'status' => $run->status->value,
            'status_label' => $this->statusLabel($run->status),
            'progress_percent' => $progress,
            'sites_total' => count($resources),
            'sites_finished' => collect($resources)->where('terminal', true)->count(),
            'datasets_total' => $datasetTotal,
            'datasets_finished' => $finishedDatasets,
            'datasets_completed' => $completedDatasets,
            'datasets_failed' => $failedDatasets,
            'datasets_cancelled' => $cancelledDatasets,
            'rows_received' => (int) $datasets->sum('rows_received'),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'pages_completed' => (int) $datasets->sum('pages_completed'),
            'coverage_start' => $coverageStart,
            'coverage_end' => $coverageEnd,
            'last_activity' => $run->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $run->status->isTerminal() && $run->status !== CollectionRunStatus::CancellationRequested,
            'resources' => $resources,
        ];
    }

    /** @return array<string, mixed> */
    private function mapResource(CollectionResourceRun $resource): array
    {
        $datasets = $resource->datasetRuns;
        $total = $datasets->count();
        $finished = $datasets->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status->isTerminal())->count();
        $progress = $total > 0
            ? round($datasets->sum(fn (CollectionDatasetRun $dataset): float => $this->datasetProgress($dataset)) / $total * 100, 1)
            : 0.0;

        $current = $datasets->first(fn (CollectionDatasetRun $dataset): bool => in_array($dataset->status, [
            CollectionRunStatus::Running,
            CollectionRunStatus::Retrying,
            CollectionRunStatus::CancellationRequested,
        ], true)) ?? $datasets->first(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Queued);

        $siteUrl = (string) ($resource->externalResource?->external_id ?? $resource->externalResource?->display_name ?? 'Search Console');
        $range = $current instanceof CollectionDatasetRun && is_array(data_get($current->metadata, 'date_range'))
            ? data_get($current->metadata, 'date_range')
            : null;

        return [
            'id' => (int) $resource->id,
            'external_resource_id' => (int) $resource->external_resource_id,
            'name' => $this->siteLabel($siteUrl),
            'site_url' => $siteUrl,
            'status' => $resource->status->value,
            'status_label' => $this->statusLabel($resource->status),
            'terminal' => $resource->status->isTerminal(),
            'progress_percent' => $progress,
            'datasets_total' => $total,
            'datasets_finished' => $finished,
            'datasets_completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
            'datasets_failed' => $datasets->where('status', CollectionRunStatus::Failed)->count(),
            'datasets_cancelled' => $datasets->where('status', CollectionRunStatus::Cancelled)->count(),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'pages_completed' => (int) $datasets->sum('pages_completed'),
            'current_dataset' => $current instanceof CollectionDatasetRun ? $this->datasetLabel($current) : null,
            'current_search_type' => $current instanceof CollectionDatasetRun ? $this->searchTypeLabel($this->searchType($current)) : null,
            'current_range' => is_array($range) && isset($range['start'], $range['end']) ? $range['start'].' → '.$range['end'] : null,
            'last_activity' => $resource->last_activity_at?->diffForHumans() ?? '—',
            'can_stop' => ! $resource->status->isTerminal() && $resource->status !== CollectionRunStatus::CancellationRequested,
            'surfaces' => $this->surfaceGroups($datasets),
            'errors' => $datasets
                ->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Failed)
                ->map(fn (CollectionDatasetRun $dataset): array => $this->mapDatasetError($dataset))
                ->values()
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function surfaceGroups(Collection $datasets): array
    {
        return $datasets
            ->filter(fn (CollectionDatasetRun $dataset): bool => $this->searchType($dataset) !== null)
            ->groupBy(fn (CollectionDatasetRun $dataset): string => (string) $this->searchType($dataset))
            ->map(function (Collection $group, string $searchType): array {
                $total = $group->count();
                $progress = $total > 0
                    ? round($group->sum(fn (CollectionDatasetRun $dataset): float => $this->datasetProgress($dataset)) / $total * 100, 1)
                    : 0.0;
                $current = $group->first(fn (CollectionDatasetRun $dataset): bool => in_array($dataset->status, [
                    CollectionRunStatus::Running,
                    CollectionRunStatus::Retrying,
                    CollectionRunStatus::CancellationRequested,
                ], true)) ?? $group->first(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Queued);

                return [
                    'key' => $searchType,
                    'label' => $this->searchTypeLabel($searchType),
                    'progress_percent' => $progress,
                    'finished' => $group->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status->isTerminal())->count(),
                    'total' => $total,
                    'failed' => $group->where('status', CollectionRunStatus::Failed)->count(),
                    'current' => $current instanceof CollectionDatasetRun ? $this->datasetLabel($current) : null,
                ];
            })
            ->sortBy(fn (array $surface): int => $this->searchTypeOrder((string) $surface['key']))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function mapIssue(CollectionResourceRun $resource): array
    {
        $siteUrl = (string) ($resource->externalResource?->external_id ?? $resource->externalResource?->display_name ?? 'Search Console');
        $errors = $resource->datasetRuns
            ->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Failed)
            ->map(fn (CollectionDatasetRun $dataset): array => $this->mapDatasetError($dataset))
            ->values()
            ->all();

        return [
            'resource_run_id' => (int) $resource->id,
            'external_resource_id' => (int) $resource->external_resource_id,
            'name' => $this->siteLabel($siteUrl),
            'site_url' => $siteUrl,
            'status_label' => $this->statusLabel($resource->status),
            'failed_count' => count($errors),
            'last_activity' => $resource->last_activity_at?->diffForHumans() ?? '—',
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function mapDatasetError(CollectionDatasetRun $dataset): array
    {
        $category = $dataset->error_category?->value;
        $code = trim((string) ($dataset->error_code ?? ''));
        $message = trim((string) ($dataset->error_message ?? ''));
        $searchType = $this->searchType($dataset);

        return [
            'label' => $this->datasetLabel($dataset),
            'search_type' => $searchType !== null ? $this->searchTypeLabel($searchType) : null,
            'category' => $category,
            'code' => $code !== '' ? $code : null,
            'message' => $message !== '' ? $message : 'Bu veri grubu tamamlanamadı. Google ayrıntılı bir hata mesajı döndürmedi.',
            'attempts' => (int) $dataset->attempt_count,
            'last_activity' => $dataset->last_activity_at?->diffForHumans() ?? '—',
        ];
    }

    private function datasetProgress(CollectionDatasetRun $dataset): float
    {
        if ($dataset->status->isTerminal()) {
            return 1.0;
        }

        $percentage = $dataset->percentage();
        if ($percentage === null) {
            return 0.0;
        }

        return min(1.0, max(0.0, $percentage / 100));
    }

    private function searchType(CollectionDatasetRun $dataset): ?string
    {
        $value = trim((string) ($dataset->execution_variant ?: data_get($dataset->metadata, 'search_type', '')));

        return $value !== '' ? $value : null;
    }

    private function datasetLabel(CollectionDatasetRun $dataset): string
    {
        $family = (string) data_get($dataset->metadata, 'source_family_id', '');

        return match ($family) {
            'GSC_RF_PROPERTY_DAILY' => 'Genel arama performansı',
            'GSC_RF_QUERY_DAILY' => 'Arama sorguları',
            'GSC_RF_PAGE_DAILY' => 'Sayfa performansı',
            'GSC_RF_QUERY_PAGE_DAILY' => 'Sorgu × sayfa ilişkileri',
            'GSC_RF_DEVICE_DAILY' => 'Cihaz dağılımı',
            'GSC_RF_COUNTRY_DAILY' => 'Ülke performansı',
            'GSC_RF_PAGE_DEVICE_DAILY' => 'Sayfa × cihaz',
            'GSC_RF_PAGE_COUNTRY_DAILY' => 'Sayfa × ülke',
            'GSC_RF_QUERY_DEVICE_DAILY' => 'Sorgu × cihaz',
            'GSC_RF_QUERY_COUNTRY_DAILY' => 'Sorgu × ülke',
            'GSC_RF_SEARCH_APPEARANCE_DAILY' => 'Arama görünümü',
            'GSC_RF_SEARCH_APPEARANCE_PAGE_DAILY' => 'Arama görünümü × sayfa',
            'GSC_RF_SITEMAPS' => 'Site haritaları',
            default => match ((string) $dataset->dataset_contract_id) {
                'gsc_sitemap_snapshot' => 'Site haritaları',
                'gsc_site_metadata' => 'Search Console mülk bilgileri',
                default => 'Search Console veri grubu',
            },
        };
    }

    private function searchTypeLabel(string $searchType): string
    {
        return match (strtolower($searchType)) {
            'web' => 'Google Web',
            'image' => 'Google Görseller',
            'video' => 'Google Video',
            'news' => 'Google Haberler',
            'discover' => 'Google Discover',
            'googlenews' => 'Google News',
            default => $searchType,
        };
    }

    private function searchTypeOrder(string $searchType): int
    {
        return match (strtolower($searchType)) {
            'web' => 0,
            'image' => 1,
            'video' => 2,
            'discover' => 3,
            'news' => 4,
            'googlenews' => 5,
            default => 99,
        };
    }

    private function statusLabel(CollectionRunStatus $status): string
    {
        return match ($status) {
            CollectionRunStatus::Queued => 'Sırada',
            CollectionRunStatus::Running => 'Çekiliyor',
            CollectionRunStatus::Retrying => 'Tekrar deneniyor',
            CollectionRunStatus::CancellationRequested => 'Durduruluyor',
            CollectionRunStatus::Completed => 'Tamamlandı',
            CollectionRunStatus::Partial => 'Kısmi tamamlandı',
            CollectionRunStatus::Failed => 'Hata',
            CollectionRunStatus::Cancelled => 'Durduruldu',
            CollectionRunStatus::Skipped => 'Atlandı',
            CollectionRunStatus::NotEligible => 'Uygun değil',
        };
    }

    private function siteLabel(string $siteUrl): string
    {
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            return substr($siteUrl, strlen('sc-domain:'));
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? preg_replace('/^www\./i', '', $host) : $siteUrl;
    }
}
