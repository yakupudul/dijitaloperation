<?php

namespace App\Services\Collection\Presentation;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreAssetBinding;
use Illuminate\Support\Collection;

/**
 * Repairs presentation state when a CollectionRun remains partial/failed after
 * successful dataset retries. DatasetRun is the execution source of truth: the
 * newest terminal row for each logical Binding × Provider × Dataset × Variant
 * wins over an older failed attempt.
 *
 * This service is presentation-only. It never mutates collection history and it
 * never calls a provider API.
 */
final class DataSyncLatestDatasetOutcomeService
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        CollectionRunStatus::Completed->value,
        CollectionRunStatus::Failed->value,
        CollectionRunStatus::Partial->value,
        CollectionRunStatus::Cancelled->value,
        CollectionRunStatus::Skipped->value,
        CollectionRunStatus::NotEligible->value,
    ];

    /** @var list<string> */
    private const SUCCESS_STATUSES = [
        CollectionRunStatus::Completed->value,
        CollectionRunStatus::Skipped->value,
        CollectionRunStatus::NotEligible->value,
    ];

    /**
     * @param array<string,mixed> $status
     * @param list<string> $capabilities
     * @param list<string> $providers
     * @return array<string,mixed>
     */
    public function reconcile(
        array $status,
        int $digitalAssetId,
        array $capabilities,
        array $providers,
    ): array {
        if (($status['active'] ?? false) === true) {
            return $status;
        }

        // The normal status service is authoritative unless it is reporting a
        // terminal failure/partial state. This keeps due/action-required logic
        // untouched while fixing stale historical failures after a successful retry.
        if (! in_array((string) ($status['state'] ?? ''), ['partial', 'failed'], true)) {
            return $status;
        }

        $bindings = CoreAssetBinding::query()
            ->where('digital_asset_id', $digitalAssetId)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->when($capabilities !== [], fn ($query) => $query->whereIn('capability', array_values(array_unique(array_map('strval', $capabilities)))))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($bindings === []) {
            return $status;
        }

        $providers = array_values(array_unique(array_filter(array_map(
            static fn ($provider): string => strtoupper(trim((string) $provider)),
            $providers,
        ))));

        $rows = CollectionDatasetRun::query()
            ->select('collection_dataset_runs.*')
            ->with('resourceRun:id,core_asset_binding_id')
            ->join('collection_resource_runs as sync_resource_runs', 'sync_resource_runs.id', '=', 'collection_dataset_runs.collection_resource_run_id')
            ->whereIn('sync_resource_runs.core_asset_binding_id', $bindings)
            ->when($providers !== [], fn ($query) => $query->whereIn('collection_dataset_runs.provider_or_source', $providers))
            ->whereIn('collection_dataset_runs.status', self::TERMINAL_STATUSES)
            ->orderByDesc('collection_dataset_runs.id')
            // Status rendering must stay cheap even for mature accounts. Because
            // rows are newest-first this is ample headroom for all provider
            // dataset/variant keys while avoiding an unbounded history scan.
            ->limit(2500)
            ->get();

        if ($rows->isEmpty()) {
            return $status;
        }

        $latest = $this->latestLogicalRows($rows);
        if ($latest->isEmpty()) {
            return $status;
        }

        $failed = $latest->filter(fn (CollectionDatasetRun $row): bool => ! in_array($this->rawStatus($row), self::SUCCESS_STATUSES, true));
        if ($failed->isNotEmpty()) {
            // There is still a genuinely unresolved latest failure. Do not hide it.
            return $status;
        }

        $finishedAt = $latest
            ->map(fn (CollectionDatasetRun $row) => $row->finished_at)
            ->filter()
            ->sortByDesc(fn ($date) => $date->getTimestamp())
            ->first();

        $status['state'] = ((int) ($status['due_count'] ?? 0)) > 0
            ? 'due'
            : (((int) ($status['action_required_count'] ?? 0)) > 0 ? 'action_required' : 'current');
        $status['error'] = null;
        $status['last_success_at'] = $finishedAt?->toIso8601String() ?? ($status['last_success_at'] ?? null);
        $status['datasets_total'] = $latest->count();
        $status['datasets_completed'] = $latest->count();
        $status['datasets_failed'] = 0;
        $status['rows_received'] = (int) $latest->sum('rows_received');
        $status['rows_written'] = (int) $latest->sum('rows_written');
        $status['run_ids'] = $latest->pluck('collection_run_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $status['providers'] = $this->reconcileProviderRows((array) ($status['providers'] ?? []), $latest, $status);

        return $status;
    }

    /**
     * @param Collection<int,CollectionDatasetRun> $rows
     * @return Collection<int,CollectionDatasetRun>
     */
    private function latestLogicalRows(Collection $rows): Collection
    {
        $seen = [];

        return $rows->filter(function (CollectionDatasetRun $row) use (&$seen): bool {
            $bindingId = (int) ($row->resourceRun?->core_asset_binding_id ?? 0);
            $provider = strtoupper((string) $row->provider_or_source);
            $variant = mb_strtolower(trim((string) ($row->execution_variant ?? '')));
            $key = implode('|', [$bindingId, $provider, (string) $row->dataset_contract_id, $variant]);

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        })->values();
    }

    /**
     * @param list<array<string,mixed>> $providerRows
     * @param Collection<int,CollectionDatasetRun> $latest
     * @param array<string,mixed> $status
     * @return list<array<string,mixed>>
     */
    private function reconcileProviderRows(array $providerRows, Collection $latest, array $status): array
    {
        return array_values(array_map(function (array $row) use ($latest, $status): array {
            $provider = strtoupper((string) ($row['provider'] ?? ''));
            $providerLatest = $latest->filter(fn (CollectionDatasetRun $dataset): bool => strtoupper((string) $dataset->provider_or_source) === $provider);

            if ($providerLatest->isEmpty()) {
                return $row;
            }

            $providerFailed = $providerLatest->contains(fn (CollectionDatasetRun $dataset): bool => ! in_array($this->rawStatus($dataset), self::SUCCESS_STATUSES, true));
            if ($providerFailed) {
                return $row;
            }

            $row['state'] = (string) ($status['state'] ?? 'current');
            $row['progress_pct'] = null;
            $row['stage'] = null;

            return $row;
        }, $providerRows));
    }

    private function rawStatus(CollectionDatasetRun $row): string
    {
        return $row->status instanceof CollectionRunStatus
            ? $row->status->value
            : (string) $row->getRawOriginal('status');
    }
}
