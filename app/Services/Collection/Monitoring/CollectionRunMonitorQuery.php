<?php

namespace App\Services\Collection\Monitoring;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DataPool\MaterializationStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Authoritative read model for collection monitoring UX.
 * Database is canonical — never Horizon/Redis/WebSocket history.
 */
final class CollectionRunMonitorQuery
{
    public function __construct(
        private readonly CollectionStatusPresenter $statuses,
        private readonly CollectionProgressPresenter $progress,
        private readonly CollectionDurationPresenter $durations,
        private readonly CollectionDatasetLabelResolver $labels,
        private readonly CollectionErrorPresenter $errors,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function activeSummaries(?User $viewer = null, int $limit = 20): array
    {
        $query = $this->scoped($viewer)
            ->with(['resourceRuns.datasetRuns', 'requestedBy:id,name'])
            ->whereIn('status', $this->activeStatuses())
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit($limit);

        return $query->get()->map(fn (CollectionRun $run) => $this->summary($run))->all();
    }

    /**
     * @param  array{status?: ?string, provider?: ?string, digital_asset_id?: ?int}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function history(?User $viewer = null, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->scoped($viewer)
            ->with(['resourceRuns', 'requestedBy:id,name', 'digitalAsset:id,name'])
            ->whereIn('status', $this->terminalStatuses())
            ->orderByDesc('finished_at')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if (! empty($filters['provider'])) {
            $provider = (string) $filters['provider'];
            $query->whereHas('resourceRuns', fn (Builder $q) => $q->where('provider_or_source', $provider));
        }

        return $query->paginate($perPage)->through(fn (CollectionRun $run) => $this->summary($run, includeChildren: false));
    }

    /**
     * @return array<string, mixed>
     */
    public function detailByUuid(string $uuid, ?User $viewer = null): array
    {
        $run = $this->scoped($viewer)
            ->with([
                'resourceRuns.datasetRuns.attempts',
                'resourceRuns.digitalAsset:id,name',
                'resourceRuns.externalResource:id,display_name,external_id,resource_type',
                'requestedBy:id,name',
                'digitalAsset:id,name',
            ])
            ->where('uuid', $uuid)
            ->first();

        if ($run === null) {
            throw (new ModelNotFoundException)->setModel(CollectionRun::class, [$uuid]);
        }

        return $this->detail($run);
    }

    /**
     * Compact poll payload — no full attempt history.
     *
     * @return array<string, mixed>
     */
    public function pollPayload(string $uuid, ?User $viewer = null): array
    {
        $detail = $this->detailByUuid($uuid, $viewer);

        foreach ($detail['resources'] as &$resource) {
            foreach ($resource['datasets'] as &$dataset) {
                unset($dataset['attempts'], $dataset['technical']);
            }
        }
        unset($resource, $dataset);

        return [
            'uuid' => $detail['uuid'],
            'updated_at' => $detail['updated_at'],
            'status' => $detail['status'],
            'summary' => $detail['summary'],
            'exceptions' => $detail['exceptions'],
            'resources' => $detail['resources'],
            'materialization' => $detail['materialization'],
            'is_terminal' => $detail['is_terminal'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(CollectionRun $run, bool $includeChildren = true): array
    {
        if ($includeChildren && ! $run->relationLoaded('resourceRuns')) {
            $run->load(['resourceRuns.datasetRuns']);
        }

        $datasets = $run->relationLoaded('datasetRuns')
            ? $run->datasetRuns
            : ($run->relationLoaded('resourceRuns')
                ? $run->resourceRuns->flatMap->datasetRuns
                : $run->datasetRuns()->get());

        $retrying = $datasets->where('status', CollectionRunStatus::Retrying)->count();
        $failed = $datasets->where('status', CollectionRunStatus::Failed)->count();
        $rowsWritten = (int) $datasets->sum('rows_written');
        $rowsReceived = (int) $datasets->sum('rows_received');
        $plan = $this->progress->runPlanCompletion($run);
        $status = $this->statuses->present($run->status);

        $payload = [
            'uuid' => $run->uuid,
            'id' => $run->id,
            'status' => $status,
            'trigger_type' => $run->trigger_type->value,
            'requested_by' => $run->requestedBy?->name,
            'digital_asset_id' => $run->digital_asset_id,
            'digital_asset_name' => $run->digitalAsset?->name,
            'contract_registry_version' => $run->contract_registry_version,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'finished_at' => optional($run->finished_at)?->toIso8601String(),
            'last_activity_at' => optional($run->last_activity_at)?->toIso8601String(),
            'updated_at' => optional($run->updated_at)?->toIso8601String(),
            'elapsed' => $this->durations->elapsed($run->started_at, $run->finished_at),
            'is_terminal' => $run->status->isTerminal(),
            'summary' => [
                'resources_total' => (int) $run->resources_total,
                'resources_completed' => (int) $run->resources_completed,
                'datasets_total' => (int) $run->datasets_total,
                'datasets_completed' => (int) $run->datasets_completed,
                'datasets_failed' => $failed,
                'datasets_retrying' => $retrying,
                'rows_received' => $rowsReceived,
                'rows_written' => $rowsWritten,
                'plan_completion' => $plan,
                'failure_summary' => $run->failure_summary,
            ],
            'providers' => $run->relationLoaded('resourceRuns')
                ? $run->resourceRuns->pluck('provider_or_source')->unique()->values()->all()
                : [],
            'connectors' => $run->relationLoaded('resourceRuns')
                ? $run->resourceRuns->pluck('provider_or_source')->unique()->values()->map(
                    fn (string $provider): array => $this->progress->connectorPlanCompletion($provider, $run->resourceRuns)
                )->all()
                : [],
            'trigger_label' => $run->metadata['collection_intent_label']
                ?? match ($run->trigger_type) {
                    CollectionTriggerType::InitialBackfill => match ($run->metadata['collection_intent'] ?? null) {
                        'meta_initial_backfill' => 'Initial Meta Ads Collection',
                        'google_initial_backfill' => 'Initial Google Collection',
                        default => 'Initial Collection',
                    },
                    default => $run->trigger_type->value,
                },
        ];

        if ($includeChildren) {
            $payload['resources'] = $run->resourceRuns
                ->sortBy('id')
                ->values()
                ->map(fn (CollectionResourceRun $r) => $this->resourceSummary($r))
                ->all();
            $payload['exceptions'] = $this->exceptions($datasets);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(CollectionRun $run): array
    {
        $base = $this->summary($run, includeChildren: true);

        $base['resources'] = $run->resourceRuns
            ->sortBy('id')
            ->values()
            ->map(fn (CollectionResourceRun $r) => $this->resourceDetail($r))
            ->all();

        // Exception-first: attention resources/datasets floated in a dedicated list.
        $base['attention_datasets'] = collect($base['resources'])
            ->flatMap(fn (array $r) => $r['datasets'] ?? [])
            ->filter(fn (array $d) => in_array($d['status']['key'], ['retrying', 'failed', 'cancellation_requested'], true))
            ->values()
            ->all();

        $base['materialization'] = $this->materializationSnapshot($run);
        $base['technical'] = [
            'collection_run_id' => $run->id,
            'uuid' => $run->uuid,
            'contract_registry_id' => $run->contract_registry_id,
            'contract_registry_version' => $run->contract_registry_version,
            'contract_registry_checksum' => $run->contract_registry_checksum,
            'formula_registry_version' => $run->formula_registry_version,
        ];

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceSummary(CollectionResourceRun $resource): array
    {
        if (! $resource->relationLoaded('datasetRuns')) {
            $resource->load('datasetRuns');
        }

        $plan = $this->progress->resourcePlanCompletion($resource);
        $retrying = $resource->datasetRuns->where('status', CollectionRunStatus::Retrying)->count();

        return [
            'uuid' => $resource->uuid,
            'provider_or_source' => $resource->provider_or_source,
            'provider_label' => $this->labels->providerLabel($resource->provider_or_source),
            'status' => $this->statuses->present($resource->status),
            'digital_asset_id' => $resource->digital_asset_id,
            'digital_asset_name' => $resource->digitalAsset?->name,
            'resource_display' => $resource->externalResource?->display_name
                ?? $resource->externalResource?->external_id
                ?? $resource->resource_kind,
            'plan_completion' => $plan,
            'datasets_retrying' => $retrying,
            'datasets_failed' => (int) $resource->datasets_failed,
            'datasets_completed' => (int) $resource->datasets_completed,
            'datasets_total' => (int) $resource->datasets_total,
            'rows_written' => (int) $resource->datasetRuns->sum('rows_written'),
            'started_at' => optional($resource->started_at)?->toIso8601String(),
            'last_activity_at' => optional($resource->last_activity_at)?->toIso8601String(),
            'elapsed' => $this->durations->elapsed($resource->started_at, $resource->finished_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceDetail(CollectionResourceRun $resource): array
    {
        $summary = $this->resourceSummary($resource);
        $datasets = $resource->datasetRuns->sortBy('id')->values();

        // Attention items first, then plan order.
        $ordered = $datasets->sortBy(function (CollectionDatasetRun $d): array {
            $priority = match ($d->status) {
                CollectionRunStatus::Failed => 0,
                CollectionRunStatus::Retrying => 1,
                CollectionRunStatus::CancellationRequested => 2,
                CollectionRunStatus::Running => 3,
                CollectionRunStatus::Queued => 4,
                default => 5,
            };

            return [$priority, $d->id];
        })->values();

        $summary['datasets'] = $ordered->map(fn (CollectionDatasetRun $d) => $this->datasetDetail($d))->all();

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function datasetDetail(CollectionDatasetRun $dataset): array
    {
        $willAutoRetry = $dataset->status === CollectionRunStatus::Retrying
            || ($dataset->retry_at !== null && ! $dataset->status->isTerminal());

        $error = $this->errors->present(
            $dataset->error_category,
            $dataset->error_message,
            $willAutoRetry,
        );

        $transfer = $this->progress->datasetTransferProgress($dataset);

        return [
            'uuid' => $dataset->uuid,
            'dataset_contract_id' => $dataset->dataset_contract_id,
            'display_name' => $this->labels->label($dataset->dataset_contract_id),
            'provider_or_source' => $dataset->provider_or_source,
            'provider_label' => $this->labels->providerLabel($dataset->provider_or_source),
            'status' => $this->statuses->present($dataset->status),
            'requirement_level' => $dataset->requirement_level->value,
            'progress' => $transfer,
            'attempt_count' => (int) $dataset->attempt_count,
            'max_attempts' => $dataset->max_attempts,
            'retry_at' => optional($dataset->retry_at)?->toIso8601String(),
            'error' => $error,
            'started_at' => optional($dataset->started_at)?->toIso8601String(),
            'finished_at' => optional($dataset->finished_at)?->toIso8601String(),
            'last_activity_at' => optional($dataset->last_activity_at)?->toIso8601String(),
            'elapsed' => $this->durations->elapsed($dataset->started_at, $dataset->finished_at),
            'attempts' => $dataset->relationLoaded('attempts')
                ? $dataset->attempts->sortBy('attempt_number')->values()->map(fn ($a) => [
                    'attempt_number' => $a->attempt_number,
                    'status' => $this->statuses->present($a->status),
                    'error_category' => $a->error_category?->value,
                    'error_message' => $this->errors->sanitize($a->error_message),
                    'started_at' => optional($a->started_at)?->toIso8601String(),
                    'finished_at' => optional($a->finished_at)?->toIso8601String(),
                ])->all()
                : [],
            'technical' => [
                'dataset_run_id' => $dataset->id,
                'request_family_id' => $dataset->request_family_id,
                'contract_registry_version' => $dataset->contract_registry_version,
                'error_code' => $dataset->error_code,
            ],
        ];
    }

    /**
     * @param  Collection<int, CollectionDatasetRun>  $datasets
     * @return list<array<string, mixed>>
     */
    private function exceptions(Collection $datasets): array
    {
        $items = [];
        $retrying = $datasets->where('status', CollectionRunStatus::Retrying)->count();
        if ($retrying > 0) {
            $items[] = [
                'kind' => 'retrying',
                'count' => $retrying,
                'label' => trans_choice('operator.collection.exceptions.retrying', $retrying, ['count' => $retrying]),
            ];
        }
        $failed = $datasets->where('status', CollectionRunStatus::Failed)->count();
        if ($failed > 0) {
            $items[] = [
                'kind' => 'failed',
                'count' => $failed,
                'label' => trans_choice('operator.collection.exceptions.failed', $failed, ['count' => $failed]),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function materializationSnapshot(CollectionRun $run): ?array
    {
        if ($run->digital_asset_id === null) {
            return null;
        }

        $rows = DatasetMaterialization::query()
            ->where('digital_asset_id', $run->digital_asset_id)
            ->orderByDesc('last_collected_at')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'latest_run_status' => $this->statuses->present($run->status),
                'pool' => [
                    'status' => MaterializationStatus::NotCollected->value,
                    'label' => __('operator.collection.materialization.not_collected'),
                ],
            ];
        }

        $best = $rows->first();

        return [
            'latest_run_status' => $this->statuses->present($run->status),
            'pool' => [
                'status' => $best->status->value,
                'label' => __('operator.collection.materialization.'.$best->status->value),
                'coverage_end_date' => optional($best->coverage_end_date)?->toDateString(),
                'last_collected_at' => optional($best->last_collected_at)?->toIso8601String(),
                'row_count_approx' => (int) $best->row_count_approx,
                'partial' => (bool) $best->partial,
                'stale' => $best->status === MaterializationStatus::Stale,
            ],
            'note' => $run->status === CollectionRunStatus::Failed
                ? __('operator.collection.materialization.failed_refresh_keeps_data')
                : null,
        ];
    }

    /**
     * @return Builder<CollectionRun>
     */
    private function scoped(?User $viewer): Builder
    {
        $query = CollectionRun::query();

        if ($viewer === null) {
            return $query;
        }

        if (method_exists($viewer, 'hasRole') && $viewer->hasRole(Roles::ADMIN)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($viewer): void {
            $q->where('requested_by_user_id', $viewer->id);
        });
    }

    /**
     * @return list<string>
     */
    private function activeStatuses(): array
    {
        return [
            CollectionRunStatus::Queued->value,
            CollectionRunStatus::Running->value,
            CollectionRunStatus::Retrying->value,
            CollectionRunStatus::CancellationRequested->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function terminalStatuses(): array
    {
        return [
            CollectionRunStatus::Completed->value,
            CollectionRunStatus::Partial->value,
            CollectionRunStatus::Failed->value,
            CollectionRunStatus::Cancelled->value,
            CollectionRunStatus::Skipped->value,
            CollectionRunStatus::NotEligible->value,
        ];
    }
}
