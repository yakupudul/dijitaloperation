<?php

namespace App\Services\Collection\Presentation;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Services\DataPool\Freshness\Support\DueCollectionItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Customer-friendly, provider-neutral live status for the canonical Collection Engine.
 *
 * This service never calls a provider API and never invents progress. It reads the
 * CollectionRun / CollectionDatasetRun rows that the existing engine already owns.
 */
final class DataSyncStatusService
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        CollectionRunStatus::Queued->value,
        CollectionRunStatus::Running->value,
        CollectionRunStatus::Retrying->value,
        CollectionRunStatus::CancellationRequested->value,
    ];

    /** @var list<string> */
    private const SUCCESS_STATUSES = [
        CollectionRunStatus::Completed->value,
        CollectionRunStatus::Skipped->value,
        CollectionRunStatus::NotEligible->value,
    ];

    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        CollectionRunStatus::Completed->value,
        CollectionRunStatus::Failed->value,
        CollectionRunStatus::Partial->value,
        CollectionRunStatus::Cancelled->value,
        CollectionRunStatus::Skipped->value,
        CollectionRunStatus::NotEligible->value,
    ];

    public function __construct(
        private readonly DueCollectionQueryService $dueQuery,
    ) {}

    /**
     * @param list<int> $bindingIds
     * @param list<string>|null $providerSources
     * @return array<string,mixed>
     */
    public function forBindings(array $bindingIds, ?array $providerSources = null): array
    {
        $bindingIds = array_values(array_unique(array_filter(array_map('intval', $bindingIds), static fn (int $id): bool => $id > 0)));
        $providers = $this->normalizeProviders($providerSources);

        if ($bindingIds === []) {
            return $this->emptyStatus('unconfigured');
        }

        $activeRuns = $this->runQuery($bindingIds, $providers)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->with(['resourceRuns.datasetRuns'])
            ->orderByDesc('id')
            ->get();

        $latestTerminal = $this->runQuery($bindingIds, $providers)
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->with(['resourceRuns.datasetRuns'])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        $latestSuccess = $this->runQuery($bindingIds, $providers)
            ->whereIn('status', self::SUCCESS_STATUSES)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        $due = $this->dueItems($bindingIds, $providers);
        $executableDue = array_values(array_filter($due, static fn (DueCollectionItem $item): bool => ! $item->actionRequired));
        $actionRequired = array_values(array_filter($due, static fn (DueCollectionItem $item): bool => $item->actionRequired));

        if ($activeRuns->isNotEmpty()) {
            return $this->presentActive($activeRuns, $bindingIds, $providers, $latestSuccess, $due);
        }

        $base = $this->emptyStatus($executableDue !== [] ? 'due' : ($actionRequired !== [] ? 'action_required' : 'current'));
        $base['due_count'] = count($executableDue);
        $base['action_required_count'] = count($actionRequired);
        $base['last_success_at'] = $this->dateTime($latestSuccess?->finished_at);
        $base['data_through'] = $this->dataThrough($latestSuccess, $bindingIds, $providers);
        $base['providers'] = $this->providerRows($bindingIds, $providers, $due);

        if ($latestTerminal !== null && in_array($latestTerminal->status->value, [CollectionRunStatus::Failed->value, CollectionRunStatus::Partial->value], true)) {
            $newerSuccess = $latestSuccess?->finished_at;
            $terminalAt = $latestTerminal->finished_at;
            if ($newerSuccess === null || ($terminalAt !== null && $newerSuccess->lt($terminalAt))) {
                $base['state'] = $latestTerminal->status === CollectionRunStatus::Partial ? 'partial' : 'failed';
                $base['error'] = $latestTerminal->failure_summary;
            }
        }

        return $base;
    }

    /** @param list<int> $bindingIds @param list<string> $providers */
    private function runQuery(array $bindingIds, array $providers): Builder
    {
        return CollectionRun::query()->whereHas('resourceRuns', function (Builder $query) use ($bindingIds, $providers): void {
            $query->whereIn('core_asset_binding_id', $bindingIds);
            if ($providers !== []) {
                $query->whereIn('provider_or_source', $providers);
            }
        });
    }

    /**
     * @param list<int> $bindingIds
     * @param list<string> $providers
     * @return list<DueCollectionItem>
     */
    private function dueItems(array $bindingIds, array $providers): array
    {
        try {
            return $this->dueQuery->query([
                'core_asset_binding_ids' => $bindingIds,
                'provider_sources' => $providers !== [] ? $providers : null,
                'include_action_required' => true,
            ]);
        } catch (\Throwable) {
            // Status display must never take the workspace offline.
            return [];
        }
    }

    /**
     * @param Collection<int,CollectionRun> $runs
     * @param list<int> $bindingIds
     * @param list<string> $providers
     * @param list<DueCollectionItem> $due
     * @return array<string,mixed>
     */
    private function presentActive(Collection $runs, array $bindingIds, array $providers, ?CollectionRun $latestSuccess, array $due): array
    {
        $datasetRuns = $runs
            ->flatMap(fn (CollectionRun $run) => $run->resourceRuns)
            ->filter(fn ($resourceRun): bool => in_array((int) $resourceRun->core_asset_binding_id, $bindingIds, true)
                && ($providers === [] || in_array(strtoupper((string) $resourceRun->provider_or_source), $providers, true)))
            ->flatMap(fn ($resourceRun) => $resourceRun->datasetRuns)
            ->values();

        $total = $datasetRuns->count();
        $completed = 0;
        $failed = 0;
        $work = 0.0;
        $knownInternalProgress = false;

        foreach ($datasetRuns as $datasetRun) {
            $status = $datasetRun->status?->value ?? (string) $datasetRun->status;
            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                $work += 1.0;
                if (in_array($status, [CollectionRunStatus::Failed->value, CollectionRunStatus::Partial->value, CollectionRunStatus::Cancelled->value], true)) {
                    $failed++;
                } else {
                    $completed++;
                }
                continue;
            }

            $current = (int) ($datasetRun->progress_current ?? 0);
            $progressTotal = (int) ($datasetRun->progress_total ?? 0);
            if ($progressTotal > 0) {
                $knownInternalProgress = true;
                $work += max(0.0, min(1.0, $current / $progressTotal));
            }
        }

        $progress = $total > 0 ? (int) round(($work / $total) * 100) : null;
        $progress = $progress !== null ? max(1, min(99, $progress)) : null;

        /** @var CollectionDatasetRun|null $currentDataset */
        $currentDataset = $datasetRuns
            ->filter(function (CollectionDatasetRun $row): bool {
                $status = $row->status?->value ?? (string) $row->status;
                return ! in_array($status, self::TERMINAL_STATUSES, true);
            })
            ->sortByDesc(fn (CollectionDatasetRun $row) => $row->started_at?->getTimestamp() ?? $row->id)
            ->first();

        $latestRun = $runs->sortByDesc('id')->first();
        $state = $runs->contains(fn (CollectionRun $run): bool => $run->status === CollectionRunStatus::Retrying)
            ? 'retrying'
            : ($runs->every(fn (CollectionRun $run): bool => $run->status === CollectionRunStatus::Queued) ? 'queued' : 'running');

        return [
            'state' => $state,
            'active' => true,
            'progress_pct' => $progress,
            'progress_determinate' => $total > 0 && ($completed > 0 || $failed > 0 || $knownInternalProgress),
            'stage' => $this->humanStage($currentDataset),
            'technical_stage' => $currentDataset?->stage,
            'current_dataset' => $currentDataset?->dataset_contract_id,
            'datasets_total' => $total,
            'datasets_completed' => $completed,
            'datasets_failed' => $failed,
            'rows_received' => (int) $datasetRuns->sum('rows_received'),
            'rows_written' => (int) $datasetRuns->sum('rows_written'),
            'started_at' => $this->dateTime($runs->min('started_at')),
            'last_success_at' => $this->dateTime($latestSuccess?->finished_at),
            'data_through' => $this->dataThrough($latestRun, $bindingIds, $providers),
            'due_count' => count(array_filter($due, static fn (DueCollectionItem $item): bool => ! $item->actionRequired)),
            'action_required_count' => count(array_filter($due, static fn (DueCollectionItem $item): bool => $item->actionRequired)),
            'error' => null,
            'providers' => $this->providerRowsFromRuns($runs, $bindingIds, $providers),
            'run_ids' => $runs->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param list<int> $bindingIds
     * @param list<string> $providers
     * @param list<DueCollectionItem> $due
     * @return list<array<string,mixed>>
     */
    private function providerRows(array $bindingIds, array $providers, array $due): array
    {
        $providerList = $providers !== []
            ? $providers
            : array_values(array_unique(array_map(static fn (DueCollectionItem $item): string => strtoupper($item->providerOrSource), $due)));

        $rows = [];
        foreach ($providerList as $provider) {
            $latest = $this->runQuery($bindingIds, [$provider])
                ->whereIn('status', self::TERMINAL_STATUSES)
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->first();
            $providerDue = array_values(array_filter($due, static fn (DueCollectionItem $item): bool => strtoupper($item->providerOrSource) === $provider && ! $item->actionRequired));

            $rows[] = [
                'provider' => $provider,
                'label' => $this->providerLabel($provider),
                'state' => $providerDue !== [] ? 'due' : (($latest?->status === CollectionRunStatus::Failed) ? 'failed' : 'current'),
                'progress_pct' => null,
                'stage' => null,
                'data_through' => $this->dataThrough($latest, $bindingIds, [$provider]),
            ];
        }

        return $rows;
    }

    /**
     * @param Collection<int,CollectionRun> $runs
     * @param list<int> $bindingIds
     * @param list<string> $providers
     * @return list<array<string,mixed>>
     */
    private function providerRowsFromRuns(Collection $runs, array $bindingIds, array $providers): array
    {
        $providerList = $providers !== []
            ? $providers
            : $runs->flatMap(fn (CollectionRun $run) => $run->resourceRuns->pluck('provider_or_source'))
                ->map(static fn ($provider): string => strtoupper((string) $provider))
                ->unique()->values()->all();

        $rows = [];
        foreach ($providerList as $provider) {
            $providerDatasets = $runs
                ->flatMap(fn (CollectionRun $run) => $run->resourceRuns)
                ->filter(fn ($resourceRun): bool => in_array((int) $resourceRun->core_asset_binding_id, $bindingIds, true)
                    && strtoupper((string) $resourceRun->provider_or_source) === $provider)
                ->flatMap(fn ($resourceRun) => $resourceRun->datasetRuns)
                ->values();

            $total = $providerDatasets->count();
            $work = 0.0;
            foreach ($providerDatasets as $datasetRun) {
                $status = $datasetRun->status?->value ?? (string) $datasetRun->status;
                if (in_array($status, self::TERMINAL_STATUSES, true)) {
                    $work += 1.0;
                } elseif ((int) ($datasetRun->progress_total ?? 0) > 0) {
                    $work += min(1.0, (int) ($datasetRun->progress_current ?? 0) / (int) $datasetRun->progress_total);
                }
            }

            /** @var CollectionDatasetRun|null $current */
            $current = $providerDatasets->first(function (CollectionDatasetRun $row): bool {
                $status = $row->status?->value ?? (string) $row->status;
                return ! in_array($status, self::TERMINAL_STATUSES, true);
            });

            $rows[] = [
                'provider' => $provider,
                'label' => $this->providerLabel($provider),
                'state' => 'running',
                'progress_pct' => $total > 0 ? max(1, min(99, (int) round(($work / $total) * 100))) : null,
                'stage' => $this->humanStage($current),
                'data_through' => null,
            ];
        }

        return $rows;
    }

    /** @param list<int> $bindingIds @param list<string> $providers */
    private function dataThrough(?CollectionRun $run, array $bindingIds, array $providers): ?string
    {
        if ($run === null) {
            return null;
        }

        $items = data_get($run->request_context, 'context.incremental_due_items', []);
        if (! is_array($items)) {
            $items = data_get($run->metadata, 'incremental_due_items', []);
        }
        if (! is_array($items)) {
            return null;
        }

        $ends = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! in_array((int) ($item['core_asset_binding_id'] ?? 0), $bindingIds, true)) {
                continue;
            }
            $provider = strtoupper((string) ($item['provider_or_source'] ?? ''));
            if ($providers !== [] && ! in_array($provider, $providers, true)) {
                continue;
            }
            $end = data_get($item, 'date_range.end');
            if (is_string($end) && $end !== '') {
                $ends[] = $end;
            }
        }

        return $ends !== [] ? max($ends) : null;
    }

    private function humanStage(?CollectionDatasetRun $run): ?string
    {
        if ($run === null) {
            return null;
        }

        $needle = strtolower(implode(' ', array_filter([
            (string) $run->dataset_contract_id,
            (string) $run->request_family_id,
            (string) $run->stage,
        ])));

        return match (true) {
            str_contains($needle, 'search_term') || str_contains($needle, 'query') => 'Arama sorguları güncelleniyor',
            str_contains($needle, 'campaign') => 'Kampanya performansı güncelleniyor',
            str_contains($needle, 'adset') || str_contains($needle, 'ad_set') || str_contains($needle, 'adgroup') || str_contains($needle, 'ad_group') => 'Reklam grupları güncelleniyor',
            str_contains($needle, 'creative') || str_contains($needle, 'video') => 'Kreatif ve video verileri güncelleniyor',
            str_contains($needle, 'typed_action') || str_contains($needle, 'conversion') => 'Dönüşümler güncelleniyor',
            str_contains($needle, 'breakdown') || str_contains($needle, 'audience') || str_contains($needle, 'geo') || str_contains($needle, 'device') => 'Kitle ve dağıtım verileri güncelleniyor',
            str_contains($needle, 'landing') || str_contains($needle, 'page') => 'Sayfa performansı güncelleniyor',
            str_contains($needle, 'event') => 'Etkinlik verileri güncelleniyor',
            str_contains($needle, 'traffic') || str_contains($needle, 'acquisition') => 'Trafik kaynakları güncelleniyor',
            str_contains($needle, 'hour') || str_contains($needle, 'date') => 'Zaman bazlı performans güncelleniyor',
            str_contains($needle, 'ad') => 'Reklam performansı güncelleniyor',
            default => 'Veriler güncelleniyor',
        };
    }

    private function providerLabel(string $provider): string
    {
        return match (strtoupper($provider)) {
            'GOOGLE_ANALYTICS', 'GA4' => 'Google Analytics',
            'GOOGLE_SEARCH_CONSOLE', 'SEARCH_CONSOLE', 'GSC' => 'Search Console',
            'GOOGLE_ADS' => 'Google Ads',
            'META_ADS' => 'Meta Ads',
            default => ucwords(strtolower(str_replace('_', ' ', $provider))),
        };
    }

    /** @param list<string>|null $providers @return list<string> */
    private function normalizeProviders(?array $providers): array
    {
        if ($providers === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($provider): string => strtoupper(trim((string) $provider)),
            $providers,
        ))));
    }

    private function dateTime(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }

    /** @return array<string,mixed> */
    private function emptyStatus(string $state): array
    {
        return [
            'state' => $state,
            'active' => false,
            'progress_pct' => null,
            'progress_determinate' => false,
            'stage' => null,
            'technical_stage' => null,
            'current_dataset' => null,
            'datasets_total' => 0,
            'datasets_completed' => 0,
            'datasets_failed' => 0,
            'rows_received' => 0,
            'rows_written' => 0,
            'started_at' => null,
            'last_success_at' => null,
            'data_through' => null,
            'due_count' => 0,
            'action_required_count' => 0,
            'error' => null,
            'providers' => [],
            'run_ids' => [],
        ];
    }
}
