<?php

namespace App\Services\Collection\Presentation;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resolves a UI sync scope to the canonical provider collection path.
 * Google Ads keeps its resource-first smart updater; GA4/GSC/Meta keep the
 * freshness planner / incremental engine. No provider API is called by status().
 */
final class DataSyncScopeService
{
    public function __construct(
        private readonly DataSyncStatusService $status,
        private readonly StartIncrementalCollectionService $incremental,
        private readonly GoogleAdsCentralCollectionService $googleAds,
    ) {}

    /** @param list<string> $capabilities @param list<string> $providers @return array<string,mixed> */
    public function status(int $digitalAssetId, array $capabilities, array $providers): array
    {
        $bindings = $this->bindings($digitalAssetId, $capabilities);
        if ($bindings->isEmpty()) {
            return $this->unconfigured();
        }

        $providers = $this->providers($providers);
        if ($providers === ['GOOGLE_ADS']) {
            return $this->googleAdsStatus($bindings->pluck('external_resource_id')->filter()->map(fn ($id) => (int) $id)->values()->all());
        }

        return $this->status->forBindings(
            $bindings->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            $providers,
        );
    }

    /**
     * @param list<string> $capabilities
     * @param list<string> $providers
     * @return array{outcome:string,message:string}
     */
    public function start(int $digitalAssetId, array $capabilities, array $providers, ?User $user): array
    {
        $bindings = $this->bindings($digitalAssetId, $capabilities);
        if ($bindings->isEmpty()) {
            return ['outcome' => 'action_required', 'message' => 'Bu veri kaynağı henüz dijital varlığa bağlı değil.'];
        }

        $providers = $this->providers($providers);

        if ($providers === ['GOOGLE_ADS']) {
            $resourceIds = $bindings->pluck('external_resource_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $resources = CoreExternalResource::query()->with('integration')->whereIn('id', $resourceIds)->get();
            $integration = $resources->first()?->integration;
            if ($integration === null || $resources->isEmpty()) {
                return ['outcome' => 'action_required', 'message' => 'Google Ads bağlantısı güncelleme için hazır değil.'];
            }

            try {
                $run = $this->googleAds->startSmartUpdate($integration, $resources->pluck('id')->all(), $user);
                return ['outcome' => 'started', 'message' => 'Google Ads güncellemesi başlatıldı. Run #'.$run->id.'.'];
            } catch (Throwable $e) {
                $message = $e->getMessage();
                if (str_contains(mb_strtolower($message), 'zaten devam ediyor')) {
                    return ['outcome' => 'active_equivalent', 'message' => 'Google Ads güncellemesi zaten devam ediyor.'];
                }
                return ['outcome' => 'failed', 'message' => $message];
            }
        }

        $result = $this->incremental->startForBindingIds(
            $bindings->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            $user,
            $providers,
            ['collection_intent_label' => 'Kullanıcı tarafından başlatılan veri güncellemesi'],
        );

        return ['outcome' => $result->outcome, 'message' => $result->message];
    }

    /** @param list<string> $capabilities @return Collection<int,CoreAssetBinding> */
    private function bindings(int $digitalAssetId, array $capabilities): Collection
    {
        $capabilities = array_values(array_unique(array_filter(array_map('strval', $capabilities))));

        return CoreAssetBinding::query()
            ->where('digital_asset_id', $digitalAssetId)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->when($capabilities !== [], fn ($query) => $query->whereIn('capability', $capabilities))
            ->orderBy('id')
            ->get();
    }

    /** @param list<int> $externalResourceIds @return array<string,mixed> */
    private function googleAdsStatus(array $externalResourceIds): array
    {
        if ($externalResourceIds === []) {
            return $this->unconfigured();
        }

        $runs = CollectionResourceRun::query()
            ->where('provider_or_source', 'GOOGLE_ADS')
            ->whereIn('external_resource_id', $externalResourceIds)
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->with(['datasetRuns', 'collectionRun'])
            ->orderByDesc('id')
            ->limit(max(10, count($externalResourceIds) * 4))
            ->get();

        $active = $runs->filter(fn (CollectionResourceRun $run): bool => in_array($run->status, [
            CollectionRunStatus::Queued,
            CollectionRunStatus::Running,
            CollectionRunStatus::Retrying,
            CollectionRunStatus::CancellationRequested,
        ], true));

        $latestSuccess = $runs->first(fn (CollectionResourceRun $run): bool => $run->status === CollectionRunStatus::Completed);
        $latest = $runs->first();

        if ($active->isEmpty()) {
            $state = match ($latest?->status) {
                CollectionRunStatus::Failed => 'failed',
                CollectionRunStatus::Partial, CollectionRunStatus::Cancelled => 'partial',
                default => $latestSuccess ? 'current' : 'due',
            };

            return array_merge($this->base($state), [
                'last_success_at' => $latestSuccess?->finished_at?->toIso8601String(),
                'data_through' => $this->resourceDataThrough($latestSuccess),
                'error' => $latest?->error_summary,
                'providers' => [[
                    'provider' => 'GOOGLE_ADS', 'label' => 'Google Ads', 'state' => $state,
                    'progress_pct' => null, 'stage' => null, 'data_through' => $this->resourceDataThrough($latestSuccess),
                ]],
            ]);
        }

        $datasets = $active->flatMap(fn (CollectionResourceRun $run) => $run->datasetRuns)->values();
        $total = $datasets->count();
        $work = 0.0;
        $completed = 0;
        $failed = 0;
        $hasInternal = false;

        foreach ($datasets as $dataset) {
            if ($dataset->status->isTerminal()) {
                $work += 1;
                if ($dataset->status === CollectionRunStatus::Completed || $dataset->status === CollectionRunStatus::Skipped || $dataset->status === CollectionRunStatus::NotEligible) $completed++;
                else $failed++;
            } elseif ((int) ($dataset->progress_total ?? 0) > 0) {
                $hasInternal = true;
                $work += min(1, (int) ($dataset->progress_current ?? 0) / (int) $dataset->progress_total);
            }
        }

        $current = $datasets->first(fn ($dataset) => ! $dataset->status->isTerminal());
        $progress = $total > 0 ? max(1, min(99, (int) round(($work / $total) * 100))) : null;
        $state = $active->contains(fn (CollectionResourceRun $run) => $run->status === CollectionRunStatus::Retrying) ? 'retrying' : ($active->every(fn (CollectionResourceRun $run) => $run->status === CollectionRunStatus::Queued) ? 'queued' : 'running');

        return array_merge($this->base($state), [
            'active' => true,
            'progress_pct' => $progress,
            'progress_determinate' => $total > 0 && ($completed > 0 || $failed > 0 || $hasInternal),
            'stage' => $this->humanStage((string) ($current?->dataset_contract_id ?? ''), (string) ($current?->stage ?? '')),
            'technical_stage' => $current?->stage,
            'current_dataset' => $current?->dataset_contract_id,
            'datasets_total' => $total,
            'datasets_completed' => $completed,
            'datasets_failed' => $failed,
            'rows_received' => (int) $datasets->sum('rows_received'),
            'rows_written' => (int) $datasets->sum('rows_written'),
            'started_at' => $active->min(fn (CollectionResourceRun $run) => $run->started_at?->toIso8601String()),
            'last_success_at' => $latestSuccess?->finished_at?->toIso8601String(),
            'data_through' => $this->resourceDataThrough($active->first()),
            'providers' => [[
                'provider' => 'GOOGLE_ADS', 'label' => 'Google Ads', 'state' => $state,
                'progress_pct' => $progress, 'stage' => $this->humanStage((string) ($current?->dataset_contract_id ?? ''), (string) ($current?->stage ?? '')), 'data_through' => null,
            ]],
            'run_ids' => $active->pluck('collection_run_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ]);
    }

    private function resourceDataThrough(?CollectionResourceRun $resourceRun): ?string
    {
        if ($resourceRun === null) return null;
        $ends = $resourceRun->datasetRuns->map(fn ($dataset) => data_get($dataset->metadata, 'date_range.end'))->filter(fn ($end) => is_string($end) && $end !== '')->values();
        return $ends->isEmpty() ? null : (string) $ends->max();
    }

    private function humanStage(string $dataset, string $stage): string
    {
        $needle = mb_strtolower($dataset.' '.$stage);
        return match (true) {
            str_contains($needle, 'search_term') => 'Arama sorguları güncelleniyor',
            str_contains($needle, 'campaign') => 'Kampanya performansı güncelleniyor',
            str_contains($needle, 'ad_group') => 'Reklam grupları güncelleniyor',
            str_contains($needle, 'conversion') => 'Dönüşümler güncelleniyor',
            str_contains($needle, 'geo') || str_contains($needle, 'device') => 'Dağıtım kırılımları güncelleniyor',
            str_contains($needle, 'ad') => 'Reklam performansı güncelleniyor',
            default => 'Google Ads verileri güncelleniyor',
        };
    }

    /** @param list<string> $providers @return list<string> */
    private function providers(array $providers): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($provider) => strtoupper(trim((string) $provider)), $providers))));
    }

    /** @return array<string,mixed> */
    private function unconfigured(): array
    {
        return $this->base('unconfigured');
    }

    /** @return array<string,mixed> */
    private function base(string $state): array
    {
        return [
            'state' => $state, 'active' => false, 'progress_pct' => null, 'progress_determinate' => false,
            'stage' => null, 'technical_stage' => null, 'current_dataset' => null,
            'datasets_total' => 0, 'datasets_completed' => 0, 'datasets_failed' => 0,
            'rows_received' => 0, 'rows_written' => 0, 'started_at' => null, 'last_success_at' => null,
            'data_through' => null, 'due_count' => 0, 'action_required_count' => 0,
            'error' => null, 'providers' => [], 'run_ids' => [],
        ];
    }
}
