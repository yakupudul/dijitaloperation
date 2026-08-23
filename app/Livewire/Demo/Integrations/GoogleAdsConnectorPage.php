<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Google Ads')]
class GoogleAdsConnectorPage extends Component
{
    #[Url(as: 'tab', history: true)]
    public string $tab = 'accounts';

    #[Url]
    public string $q = '';

    #[Url]
    public string $state = 'all';

    /** @var list<string> */
    public array $selectedResourceIds = [];

    public ?string $actionMessage = null;

    private const array TABS = ['accounts', 'data', 'activity'];

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'accounts';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function collectSelected(GoogleAdsCentralCollectionService $collector): void
    {
        $this->authorizeOperator();
        $integration = $this->googleIntegration();
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->selectedResourceIds))));
        if ($ids === []) {
            $this->actionMessage = 'Önce veri çekilecek en az bir Google Ads hesabı seçin.';

            return;
        }

        try {
            $run = $collector->startSmartUpdate($integration, $ids, auth()->user());
            $this->selectedResourceIds = [];
            $this->tab = 'activity';
            $this->actionMessage = (string) data_get($run->metadata, 'collection_intent_label', 'Google Ads veri toplama').' başlatıldı. Run #'.$run->id.'.';
        } catch (Throwable $e) {
            $this->actionMessage = 'Google Ads veri toplama başlatılamadı: '.$e->getMessage();
        }
    }

    public function collectOne(int $externalResourceId, GoogleAdsCentralCollectionService $collector): void
    {
        $this->selectedResourceIds = [(string) $externalResourceId];
        $this->collectSelected($collector);
    }

    public function selectAllVisible(): void
    {
        $ids = collect($this->resourceRows())
            ->filter(fn (array $row): bool => $row['selectable_for_collection'])
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();
        $this->selectedResourceIds = $ids;
    }

    public function clearSelection(): void
    {
        $this->selectedResourceIds = [];
    }

    public function render(): View
    {
        $integration = $this->googleIntegration(false);
        $rows = $integration instanceof CoreIntegration ? $this->resourceRows($integration) : [];
        $selected = count($this->selectedResourceIds);
        $materializations = $integration instanceof CoreIntegration ? $this->materializationSummary($integration) : [];

        return view('livewire.demo.integrations.google-ads-connector-page', [
            'integration' => $integration,
            'rows' => $rows,
            'selectedCount' => $selected,
            'stats' => [
                'accounts' => count($rows),
                'collectable' => collect($rows)->where('provider_selectable', true)->count(),
                'collected' => collect($rows)->where('data_state', 'collected')->count(),
                'attention' => collect($rows)->whereIn('data_state', ['needs_repair', 'resume'])->count(),
                'collecting' => collect($rows)->where('data_state', 'collecting')->count(),
            ],
            'materializations' => $materializations,
            'datasetCatalog' => collect(GoogleAdsCentralRequestFamilyCatalog::definitions())
                ->map(fn (array $definition, string $family): array => [
                    'family' => $family,
                    'label' => $definition['label'],
                    'dataset' => $definition['dataset_id'],
                    'layer' => $definition['layer'],
                    'dated' => $definition['requires_date_range'],
                ])
                ->values()
                ->all(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function resourceRows(?CoreIntegration $integration = null): array
    {
        $integration ??= $this->googleIntegration(false);
        if (! $integration instanceof CoreIntegration) {
            return [];
        }

        $resources = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::GOOGLE)
            ->where('resource_type', GoogleResourceType::GOOGLE_ADS_CUSTOMER)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->orderBy('display_name')
            ->get();

        $resourceIds = $resources->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $runs = $resourceIds === []
            ? collect()
            : CollectionResourceRun::query()
                ->where('provider_or_source', 'GOOGLE_ADS')
                ->whereNull('digital_asset_id')
                ->where('metadata->collection_scope', 'provider_resource_first')
                ->whereIn('external_resource_id', $resourceIds)
                ->with(['datasetRuns', 'collectionRun'])
                ->orderByDesc('id')
                ->get()
                ->groupBy('external_resource_id');

        $bindings = $resourceIds === []
            ? collect()
            : CoreAssetBinding::query()
                ->where('capability', 'google_ads')
                ->whereIn('external_resource_id', $resourceIds)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->with('digitalAsset.brand')
                ->get()
                ->groupBy('external_resource_id');

        $rows = $resources->map(function (CoreExternalResource $resource) use ($runs, $bindings): array {
            $meta = is_array($resource->metadata) ? $resource->metadata : [];
            /** @var Collection<int,CollectionResourceRun> $history */
            $history = $runs->get($resource->id, collect());
            $active = $history->first(fn (CollectionResourceRun $run): bool => in_array($run->status, [
                CollectionRunStatus::Queued,
                CollectionRunStatus::Running,
                CollectionRunStatus::Retrying,
                CollectionRunStatus::CancellationRequested,
            ], true));
            $completed = $history->first(fn (CollectionResourceRun $run): bool => $run->status === CollectionRunStatus::Completed);
            $latest = $history->first();
            $attention = $latest instanceof CollectionResourceRun
                && in_array($latest->status, [CollectionRunStatus::Partial, CollectionRunStatus::Failed, CollectionRunStatus::Cancelled], true)
                && (! $completed instanceof CollectionResourceRun || $latest->id > $completed->id);

            [$dataState, $stateLabel, $actionLabel] = match (true) {
                $active instanceof CollectionResourceRun => ['collecting', 'Çekiliyor', 'Çekiliyor'],
                $attention && $latest?->status === CollectionRunStatus::Cancelled => ['resume', 'Aktarım durduruldu', 'Devam et'],
                $attention => ['needs_repair', 'Eksik veri var', 'Eksikleri tamamla'],
                $completed instanceof CollectionResourceRun => ['collected', 'Veri mevcut', 'Şimdi güncelle'],
                default => ['not_collected', 'Henüz veri yok', 'Verileri içe aktar'],
            };

            $providerSelectable = ! (bool) ($meta['is_manager'] ?? false)
                && (! array_key_exists('selectable', $meta) || (bool) $meta['selectable']);
            $bound = $bindings->get($resource->id, collect());
            $customerId = preg_replace('/\D+/', '', (string) ($meta['customer_id'] ?? $resource->external_id)) ?? '';
            $formattedId = strlen($customerId) === 10
                ? substr($customerId, 0, 3).'-'.substr($customerId, 3, 3).'-'.substr($customerId, 6)
                : $customerId;

            $coverage = $completed?->datasetRuns
                ->map(fn ($dataset) => data_get($dataset->metadata, 'date_range'))
                ->filter(fn ($range): bool => is_array($range) && isset($range['start'], $range['end']));
            $coverageStart = $coverage?->min('start');
            $coverageEnd = $coverage?->max('end');

            return [
                'id' => (int) $resource->id,
                'name' => (string) ($resource->display_name ?: ($meta['descriptive_name'] ?? 'Google Ads Account')),
                'customer_id' => $customerId,
                'formatted_customer_id' => $formattedId,
                'currency' => $meta['currency_code'] ?? '—',
                'timezone' => $meta['time_zone'] ?? $meta['timezone'] ?? '—',
                'is_manager' => (bool) ($meta['is_manager'] ?? false),
                'level' => $meta['level'] ?? null,
                'provider_selectable' => $providerSelectable,
                'selectable_for_collection' => $providerSelectable && ! $active instanceof CollectionResourceRun,
                'data_state' => $dataState,
                'state_label' => $stateLabel,
                'action_label' => $actionLabel,
                'coverage_start' => $coverageStart,
                'coverage_end' => $coverageEnd,
                'last_collection' => $latest?->last_activity_at?->diffForHumans(),
                'last_success' => $completed?->finished_at?->diffForHumans(),
                'failed_datasets' => $latest?->datasetRuns->where('status', CollectionRunStatus::Failed)->count() ?? 0,
                'active_run_id' => $active?->collection_run_id,
                'bound_assets' => $bound->map(fn (CoreAssetBinding $binding): array => [
                    'id' => $binding->digital_asset_id,
                    'name' => $binding->digitalAsset?->name,
                    'brand' => $binding->digitalAsset?->brand?->name,
                ])->values()->all(),
            ];
        })->values();

        if ($this->q !== '') {
            $needle = mb_strtolower(trim($this->q));
            $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['name'].' '.$row['customer_id'].' '.$row['formatted_customer_id']), $needle));
        }
        if ($this->state !== 'all') {
            $rows = $rows->filter(fn (array $row): bool => $row['data_state'] === $this->state);
        }

        return $rows->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function materializationSummary(CoreIntegration $integration): array
    {
        $resourceIds = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', GoogleResourceType::GOOGLE_ADS_CUSTOMER)
            ->pluck('id');

        if ($resourceIds->isEmpty()) {
            return [];
        }

        return DatasetMaterialization::query()
            ->whereIn('external_resource_id', $resourceIds)
            ->where('provider_or_source', 'GOOGLE_ADS')
            ->orderBy('dataset_id')
            ->get()
            ->groupBy('dataset_id')
            ->map(function (Collection $rows, string $dataset): array {
                $start = $rows->pluck('coverage_start_date')->filter()->min();
                $end = $rows->pluck('coverage_end_date')->filter()->max();
                $lastSuccess = $rows->pluck('last_collected_at')->filter()->sortDesc()->first();

                return [
                    'dataset' => $dataset,
                    'accounts' => $rows->pluck('external_resource_id')->unique()->count(),
                    'status' => $rows->pluck('status')->map(fn ($status) => is_object($status) ? $status->value : (string) $status)->unique()->implode(', '),
                    'coverage_start' => $start?->toDateString(),
                    'coverage_end' => $end?->toDateString(),
                    'last_success' => $lastSuccess?->diffForHumans(),
                ];
            })
            ->values()
            ->all();
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

    private function authorizeOperator(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }
    }
}
