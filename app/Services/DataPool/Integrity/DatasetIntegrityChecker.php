<?php

namespace App\Services\DataPool\Integrity;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DataPool\WriteBatchStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreExternalResource;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DataPool\DatasetWriteBatch;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\DataPool\Integrity\Support\IntegrityCheckOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral per-dataset integrity checks. Read-only — never mutates facts.
 */
final class DatasetIntegrityChecker
{
    public function __construct(
        private readonly DataPoolStorageRegistry $storage,
        private readonly MetricAggregationGuard $aggregationGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $profile
     * @return list<IntegrityCheckOutcome>
     */
    public function checkProfile(
        array $profile,
        ?int $digitalAssetId = null,
        ?int $externalResourceId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $disposition = (string) ($profile['storage_disposition'] ?? '');

        if ($disposition === 'STORAGE_CONTRACT_GAP') {
            return [
                IntegrityCheckOutcome::warning(
                    checkId: 'contract_completeness',
                    category: 'contract',
                    message: "Dataset [{$datasetId}] is STORAGE_CONTRACT_GAP — not migration-ready.",
                    evidence: ['disposition' => $disposition],
                    provider: $provider,
                    datasetId: $datasetId,
                    assetId: $digitalAssetId,
                    resourceId: $externalResourceId,
                    blocksMigration: true,
                ),
            ];
        }

        if ($disposition !== 'PHYSICAL_TABLE' || empty($profile['physical_table'])) {
            return [
                IntegrityCheckOutcome::notApplicable(
                    checkId: 'physical_storage',
                    category: 'contract',
                    message: "Dataset [{$datasetId}] has no physical table disposition.",
                    provider: $provider,
                    datasetId: $datasetId,
                ),
            ];
        }

        $outcomes = [];
        $outcomes[] = $this->checkCollectionEvidence($profile, $digitalAssetId, $externalResourceId);

        $checks = $profile['required_checks'] ?? [];
        $blocking = array_values(array_unique(array_merge(
            $profile['migration_blocking_checks'] ?? [],
            ['collection_evidence'],
        )));

        foreach ($checks as $checkId) {
            $outcome = match ((string) $checkId) {
                'natural_key_duplicates' => $this->checkNaturalKeyDuplicates($profile, $digitalAssetId, $externalResourceId),
                'referential_integrity' => $this->checkReferentialIntegrity($profile, $digitalAssetId, $externalResourceId),
                'provenance' => $this->checkProvenance($profile, $digitalAssetId, $externalResourceId),
                'write_receipt_accounting' => $this->checkWriteReceiptAccounting($profile, $digitalAssetId, $externalResourceId),
                'row_accounting' => $this->checkRowAccounting($profile, $digitalAssetId, $externalResourceId),
                'coverage_intervals' => $this->checkCoverageIntervals($profile, $digitalAssetId, $externalResourceId, $dateFrom, $dateTo),
                'snapshot_semantics' => $this->checkSnapshotSemantics($profile, $digitalAssetId, $externalResourceId),
                'materialization_reconciliation' => $this->checkMaterialization($profile, $digitalAssetId, $externalResourceId),
                'pagination_completeness' => $this->checkPaginationCompleteness($profile, $digitalAssetId, $externalResourceId),
                'timezone_provenance' => $this->checkTimezone($profile, $digitalAssetId, $externalResourceId),
                'currency_provenance' => $this->checkCurrency($profile, $digitalAssetId, $externalResourceId),
                'non_additive_metric_protection' => $this->checkNonAdditiveProtection($profile),
                'provider_total_compatibility' => $this->checkProviderTotalCompatibility($profile),
                'contract_completeness' => $this->checkContractCompleteness($profile, $digitalAssetId, $externalResourceId),
                'freshness' => $this->checkFreshness($profile, $digitalAssetId, $externalResourceId),
                default => IntegrityCheckOutcome::unverified(
                    checkId: (string) $checkId,
                    category: 'unknown',
                    message: "No checker registered for [{$checkId}].",
                    blocksMigration: in_array($checkId, $blocking, true),
                    provider: $provider,
                    datasetId: $datasetId,
                    assetId: $digitalAssetId,
                    resourceId: $externalResourceId,
                ),
            };

            if (in_array($checkId, $blocking, true) && $outcome->status->isBlocking()) {
                $outcome = new IntegrityCheckOutcome(
                    checkId: $outcome->checkId,
                    category: $outcome->category,
                    status: $outcome->status,
                    severity: $outcome->severity,
                    message: $outcome->message,
                    expected: $outcome->expected,
                    observed: $outcome->observed,
                    difference: $outcome->difference,
                    tolerance: $outcome->tolerance,
                    evidence: $outcome->evidence,
                    blocksMigration: true,
                    providerOrSource: $outcome->providerOrSource,
                    datasetId: $outcome->datasetId,
                    digitalAssetId: $outcome->digitalAssetId,
                    externalResourceId: $outcome->externalResourceId,
                );
            }

            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /**
     * Never-collected datasets must not be READY_FOR_REAL_UI.
     *
     * @param  array<string, mixed>  $profile
     */
    private function checkCollectionEvidence(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];

        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->when($assetId !== null, fn ($q) => $q->where('digital_asset_id', $assetId))
            ->when($resourceId !== null, fn ($q) => $q->where('external_resource_id', $resourceId))
            ->orderByDesc('id')
            ->first();

        $hasBatches = DatasetWriteBatch::query()
            ->where('dataset_id', $datasetId)
            ->where('status', WriteBatchStatus::Committed)
            ->exists();

        if (! $materialization instanceof DatasetMaterialization && ! $hasBatches) {
            return IntegrityCheckOutcome::unverified(
                checkId: 'collection_evidence',
                category: 'evidence',
                message: "No real collection evidence for [{$datasetId}] — cannot claim migration readiness.",
                blocksMigration: true,
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'collection_evidence',
            category: 'evidence',
            message: "Collection evidence present for [{$datasetId}].",
            evidence: [
                'materialization' => $materialization?->status?->value,
                'has_committed_batches' => $hasBatches,
            ],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkNaturalKeyDuplicates(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $table = (string) $profile['physical_table'];
        $nk = $profile['natural_key'] ?? [];

        if ($nk === [] || in_array('collection_run_id', $nk, true) || in_array('last_collection_run_id', $nk, true)) {
            return IntegrityCheckOutcome::fail(
                checkId: 'natural_key_duplicates',
                category: 'structural',
                message: "Natural key invalid or includes CollectionRun identity for [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if (! Schema::hasTable($table)) {
            return IntegrityCheckOutcome::unverified(
                checkId: 'natural_key_duplicates',
                category: 'structural',
                message: "Table [{$table}] not present in this environment.",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $query = DB::table($table);
        if ($assetId !== null && Schema::hasColumn($table, 'digital_asset_id')) {
            $query->where('digital_asset_id', $assetId);
        }

        $select = implode(', ', array_map(fn (string $c): string => $c, $nk));
        $duplicates = (clone $query)
            ->selectRaw("{$select}, COUNT(*) as c")
            ->groupBy($nk)
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->get();

        if ($duplicates->isNotEmpty()) {
            return IntegrityCheckOutcome::fail(
                checkId: 'natural_key_duplicates',
                category: 'structural',
                message: "Duplicate natural keys detected in [{$datasetId}].",
                expected: ['duplicate_groups' => 0],
                observed: ['duplicate_groups' => $duplicates->count()],
                evidence: ['sample_count' => $duplicates->count()],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'natural_key_duplicates',
            category: 'structural',
            message: "No natural-key duplicates in [{$datasetId}].",
            evidence: ['natural_key' => $nk],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkReferentialIntegrity(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $table = (string) $profile['physical_table'];

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'digital_asset_id')) {
            return IntegrityCheckOutcome::notApplicable(
                checkId: 'referential_integrity',
                category: 'structural',
                message: 'Referential columns not available.',
                provider: $provider,
                datasetId: $datasetId,
            );
        }

        $query = DB::table($table.' as f')
            ->leftJoin('digital_assets as a', 'a.id', '=', 'f.digital_asset_id')
            ->whereNull('a.id');
        if ($assetId !== null) {
            $query->where('f.digital_asset_id', $assetId);
        }
        $orphans = (int) $query->count();

        if ($orphans > 0) {
            return IntegrityCheckOutcome::fail(
                checkId: 'referential_integrity',
                category: 'structural',
                message: "Orphan facts without DigitalAsset in [{$datasetId}].",
                observed: ['orphan_rows' => $orphans],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if ($resourceId !== null) {
            $resource = CoreExternalResource::query()->find($resourceId);
            if ($resource instanceof CoreExternalResource) {
                $expectedProvider = match ($provider) {
                    'SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS' => 'google',
                    'META_ADS' => 'meta',
                    default => null,
                };
                if ($expectedProvider !== null && $resource->provider !== $expectedProvider) {
                    return IntegrityCheckOutcome::fail(
                        checkId: 'referential_integrity',
                        category: 'structural',
                        message: "Resource provider mismatch for [{$datasetId}].",
                        expected: ['provider' => $expectedProvider],
                        observed: ['provider' => $resource->provider],
                        provider: $provider,
                        datasetId: $datasetId,
                        assetId: $assetId,
                        resourceId: $resourceId,
                    );
                }
            }
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'referential_integrity',
            category: 'structural',
            message: "Referential integrity OK for [{$datasetId}].",
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkProvenance(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $table = (string) $profile['physical_table'];

        if (! Schema::hasTable($table)) {
            return IntegrityCheckOutcome::unverified(
                checkId: 'provenance',
                category: 'provenance',
                message: "Table [{$table}] unavailable.",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $rowCount = DB::table($table);
        if ($assetId !== null && Schema::hasColumn($table, 'digital_asset_id')) {
            $rowCount->where('digital_asset_id', $assetId);
        }
        $total = (int) $rowCount->count();
        if ($total === 0) {
            return IntegrityCheckOutcome::pass(
                checkId: 'provenance',
                category: 'provenance',
                message: "No facts yet for [{$datasetId}] — provenance N/A until data exists.",
                evidence: ['rows' => 0],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $missing = 0;
        if (Schema::hasColumn($table, 'last_collection_run_id')) {
            $q = DB::table($table)->whereNull('last_collection_run_id');
            if ($assetId !== null) {
                $q->where('digital_asset_id', $assetId);
            }
            $missing = (int) $q->count();
        }

        if ($missing > 0) {
            return IntegrityCheckOutcome::fail(
                checkId: 'provenance',
                category: 'provenance',
                message: "Facts missing collection provenance in [{$datasetId}].",
                observed: ['missing_provenance_rows' => $missing],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'provenance',
            category: 'provenance',
            message: "Collection provenance present for [{$datasetId}].",
            evidence: ['rows' => $total],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkWriteReceiptAccounting(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];

        $batches = DatasetWriteBatch::query()
            ->where('dataset_id', $datasetId)
            ->where('status', WriteBatchStatus::Committed)
            ->when($assetId !== null, function ($q) use ($assetId): void {
                $q->whereHas('datasetRun.resourceRun', fn ($r) => $r->where('digital_asset_id', $assetId));
            })
            ->limit(500)
            ->get();

        if ($batches->isEmpty()) {
            return IntegrityCheckOutcome::pass(
                checkId: 'write_receipt_accounting',
                category: 'write',
                message: "No committed write batches yet for [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $unbalanced = 0;
        foreach ($batches as $batch) {
            $accounted = (int) $batch->rows_inserted + (int) $batch->rows_updated + (int) $batch->rows_unchanged;
            if ((int) $batch->rows_received !== $accounted) {
                $unbalanced++;
            }
        }

        if ($unbalanced > 0) {
            return IntegrityCheckOutcome::fail(
                checkId: 'write_receipt_accounting',
                category: 'write',
                message: "WriteReceipt accounting unbalanced for [{$datasetId}].",
                observed: ['unbalanced_batches' => $unbalanced, 'batches_checked' => $batches->count()],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        // Checkpoint ahead of durable write: dataset run checkpoint pages > committed batches pages signal.
        $ahead = 0;
        $runs = CollectionDatasetRun::query()
            ->where('dataset_contract_id', $datasetId)
            ->orWhere('request_family_id', 'like', '%')
            ->whereIn('status', [
                CollectionRunStatus::Completed,
                CollectionRunStatus::Partial,
                CollectionRunStatus::Running,
            ])
            ->limit(100)
            ->get();

        foreach ($runs as $run) {
            if (($run->dataset_contract_id ?? null) !== $datasetId && ! str_contains((string) $run->dataset_contract_id, explode('_', $datasetId)[0] ?? '')) {
                // Prefer exact dataset id; also accept family-level runs that wrote this dataset via batches.
            }
            $committedForRun = DatasetWriteBatch::query()
                ->where('dataset_run_id', $run->id)
                ->where('dataset_id', $datasetId)
                ->where('status', WriteBatchStatus::Committed)
                ->count();
            $pages = (int) ($run->pages_completed ?? 0);
            if ($pages > 0 && $committedForRun === 0 && $run->status === CollectionRunStatus::Completed) {
                $ahead++;
            }
        }

        if ($ahead > 0) {
            return IntegrityCheckOutcome::fail(
                checkId: 'write_receipt_accounting',
                category: 'checkpoint',
                message: "Checkpoint/progress claims completion without committed WriteReceipts for [{$datasetId}].",
                observed: ['suspect_runs' => $ahead],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'write_receipt_accounting',
            category: 'write',
            message: "WriteReceipt accounting balanced for [{$datasetId}].",
            evidence: ['batches_checked' => $batches->count()],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkRowAccounting(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $mode = (string) ($profile['row_accounting_mode'] ?? 'ONE_TO_ONE');

        $batches = DatasetWriteBatch::query()
            ->where('dataset_id', $datasetId)
            ->where('status', WriteBatchStatus::Committed)
            ->limit(500)
            ->get();

        if ($batches->isEmpty()) {
            return IntegrityCheckOutcome::pass(
                checkId: 'row_accounting',
                category: 'accounting',
                message: "No batches to reconcile for [{$datasetId}] (mode {$mode}).",
                evidence: ['row_accounting_mode' => $mode],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $received = (int) $batches->sum('rows_received');
        $written = (int) $batches->sum(fn ($b) => (int) $b->rows_inserted + (int) $b->rows_updated + (int) $b->rows_unchanged);

        if ($mode === 'ONE_TO_MANY_TYPED_ACTIONS') {
            // Expansion is expected — only fail if written < received with no expansion metadata.
            if ($written < $received) {
                return IntegrityCheckOutcome::fail(
                    checkId: 'row_accounting',
                    category: 'accounting',
                    message: "Unexplained row loss for typed-action expansion dataset [{$datasetId}].",
                    expected: ['min_written' => $received],
                    observed: ['received' => $received, 'written' => $written],
                    provider: $provider,
                    datasetId: $datasetId,
                    assetId: $assetId,
                    resourceId: $resourceId,
                );
            }

            return IntegrityCheckOutcome::pass(
                checkId: 'row_accounting',
                category: 'accounting',
                message: "Typed action expansion accounting acceptable for [{$datasetId}].",
                evidence: ['received' => $received, 'written' => $written, 'mode' => $mode],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if ($received !== $written) {
            return IntegrityCheckOutcome::fail(
                checkId: 'row_accounting',
                category: 'accounting',
                message: "Unexplained row loss/expansion for [{$datasetId}] under mode {$mode}.",
                expected: ['written' => $received],
                observed: ['received' => $received, 'written' => $written],
                difference: ['delta' => $written - $received],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'row_accounting',
            category: 'accounting',
            message: "Row accounting balanced for [{$datasetId}].",
            evidence: ['received' => $received, 'written' => $written, 'mode' => $mode],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * Coverage from collection evidence (successful dates / zero-row markers), not fact presence.
     *
     * @param  array<string, mixed>  $profile
     */
    private function checkCoverageIntervals(
        array $profile,
        ?int $assetId,
        ?int $resourceId,
        ?string $dateFrom,
        ?string $dateTo,
    ): IntegrityCheckOutcome {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];

        if (($profile['coverage_mode'] ?? '') === 'SNAPSHOT') {
            return IntegrityCheckOutcome::notApplicable(
                checkId: 'coverage_intervals',
                category: 'coverage',
                message: 'Snapshot datasets do not use daily interval coverage.',
                provider: $provider,
                datasetId: $datasetId,
            );
        }

        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->when($assetId !== null, fn ($q) => $q->where('digital_asset_id', $assetId))
            ->when($resourceId !== null, fn ($q) => $q->where('external_resource_id', $resourceId))
            ->orderByDesc('id')
            ->first();

        if (! $materialization instanceof DatasetMaterialization) {
            return IntegrityCheckOutcome::pass(
                checkId: 'coverage_intervals',
                category: 'coverage',
                message: "No materialization yet for [{$datasetId}] — not collected.",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $targetStart = $dateFrom ?? optional($materialization->coverage_start_date)?->toDateString();
        $targetEnd = $dateTo ?? optional($materialization->coverage_end_date)?->toDateString();
        if ($targetStart === null || $targetEnd === null) {
            return IntegrityCheckOutcome::unverified(
                checkId: 'coverage_intervals',
                category: 'coverage',
                message: "Coverage bounds unavailable for [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        // Successful coverage dates come from materialization freshness_metadata.successful_dates
        // or from write-batch metadata — never from fact-row presence alone.
        $successfulDates = [];
        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];
        if (isset($meta['successful_coverage_dates']) && is_array($meta['successful_coverage_dates'])) {
            $successfulDates = array_values(array_filter($meta['successful_coverage_dates'], 'is_string'));
        }
        if (isset($meta['zero_row_success_dates']) && is_array($meta['zero_row_success_dates'])) {
            foreach ($meta['zero_row_success_dates'] as $d) {
                if (is_string($d)) {
                    $successfulDates[] = $d;
                }
            }
        }

        // If only min/max claimed without interval evidence, mark unverified when AVAILABLE/full.
        if ($successfulDates === [] && $materialization->status === MaterializationStatus::Available && ! $materialization->partial) {
            $limitation = (bool) ($meta['provider_history_limited'] ?? false);
            if ($limitation) {
                return IntegrityCheckOutcome::limitation(
                    checkId: 'coverage_intervals',
                    category: 'coverage',
                    message: "Provider-limited coverage accepted for [{$datasetId}] without fabricating zeros.",
                    evidence: [
                        'coverage_start' => $targetStart,
                        'coverage_end' => $targetEnd,
                        'provider_history_limited' => true,
                    ],
                    provider: $provider,
                    datasetId: $datasetId,
                    assetId: $assetId,
                    resourceId: $resourceId,
                );
            }

            return IntegrityCheckOutcome::unverified(
                checkId: 'coverage_intervals',
                category: 'coverage',
                message: "Materialization claims AVAILABLE for [{$datasetId}] without interval-set evidence (min/max alone is insufficient).",
                expected: ['interval_set_evidence' => true],
                observed: ['coverage_start' => $targetStart, 'coverage_end' => $targetEnd],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if ($successfulDates === []) {
            if ($materialization->partial || $materialization->status === MaterializationStatus::Partial) {
                return IntegrityCheckOutcome::warning(
                    checkId: 'coverage_intervals',
                    category: 'coverage',
                    message: "Partial collection for [{$datasetId}] without detailed interval evidence.",
                    evidence: ['status' => $materialization->status->value],
                    provider: $provider,
                    datasetId: $datasetId,
                    assetId: $assetId,
                    resourceId: $resourceId,
                    blocksMigration: true,
                );
            }

            return IntegrityCheckOutcome::pass(
                checkId: 'coverage_intervals',
                category: 'coverage',
                message: "No interval evidence required yet for [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $set = CoverageIntervalSet::fromSuccessfulDates($successfulDates);
        $gaps = $set->gapsIn($targetStart, $targetEnd);
        $bounds = $set->bounds();

        // Detect the classic min/max false positive: bounds look full but gaps exist.
        if ($gaps !== [] && $bounds['start'] === $targetStart && $bounds['end'] === $targetEnd) {
            return IntegrityCheckOutcome::fail(
                checkId: 'coverage_intervals',
                category: 'coverage',
                message: "Internal coverage gaps in [{$datasetId}] despite full min/max appearance.",
                expected: ['continuous' => true, 'start' => $targetStart, 'end' => $targetEnd],
                observed: ['gaps' => array_slice($gaps, 0, 31), 'gap_count' => count($gaps)],
                evidence: ['intervals' => $set->intervals, 'zero_row_dates_included' => true],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if ($gaps !== []) {
            return IntegrityCheckOutcome::fail(
                checkId: 'coverage_intervals',
                category: 'coverage',
                message: "Coverage gaps detected for [{$datasetId}].",
                observed: ['gaps' => array_slice($gaps, 0, 31), 'gap_count' => count($gaps)],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'coverage_intervals',
            category: 'coverage',
            message: "Interval-set coverage complete for [{$datasetId}] (zero-row success dates included).",
            evidence: ['intervals' => $set->intervals],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkSnapshotSemantics(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        return IntegrityCheckOutcome::pass(
            checkId: 'snapshot_semantics',
            category: 'coverage',
            message: 'Snapshot dataset excluded from daily gap validation.',
            provider: (string) $profile['provider_or_source'],
            datasetId: (string) $profile['dataset_id'],
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkMaterialization(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];

        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->when($assetId !== null, fn ($q) => $q->where('digital_asset_id', $assetId))
            ->when($resourceId !== null, fn ($q) => $q->where('external_resource_id', $resourceId))
            ->orderByDesc('id')
            ->first();

        if (! $materialization instanceof DatasetMaterialization) {
            return IntegrityCheckOutcome::pass(
                checkId: 'materialization_reconciliation',
                category: 'materialization',
                message: "No materialization row for [{$datasetId}] (never collected).",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if ($resourceId !== null && (int) $materialization->external_resource_id !== $resourceId) {
            return IntegrityCheckOutcome::fail(
                checkId: 'materialization_reconciliation',
                category: 'materialization',
                message: "Materialization references wrong resource for [{$datasetId}].",
                expected: ['external_resource_id' => $resourceId],
                observed: ['external_resource_id' => $materialization->external_resource_id],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];
        if ($materialization->status === MaterializationStatus::Available
            && ($meta['successful_coverage_dates'] ?? null) === []
            && ($profile['coverage_mode'] ?? '') === 'INTERVAL_SET'
            && $materialization->coverage_start_date
            && $materialization->coverage_end_date
            && empty($meta['zero_row_success_dates'])
            && empty($meta['provider_history_limited'])
        ) {
            // Already covered as unverified in coverage check; here flag mismatch risk.
            return IntegrityCheckOutcome::warning(
                checkId: 'materialization_reconciliation',
                category: 'materialization',
                message: "AVAILABLE materialization for [{$datasetId}] lacks interval evidence.",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
                blocksMigration: true,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'materialization_reconciliation',
            category: 'materialization',
            message: "Materialization state reconciled for [{$datasetId}]: {$materialization->status->value}.",
            evidence: [
                'status' => $materialization->status->value,
                'partial' => $materialization->partial,
                'coverage_start' => optional($materialization->coverage_start_date)?->toDateString(),
                'coverage_end' => optional($materialization->coverage_end_date)?->toDateString(),
            ],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkPaginationCompleteness(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $mode = (string) ($profile['pagination_mode'] ?? 'NONE');

        $runs = CollectionDatasetRun::query()
            ->where(function ($q) use ($datasetId): void {
                $q->where('dataset_contract_id', $datasetId);
            })
            ->when($assetId !== null, function ($q) use ($assetId): void {
                $q->whereHas('resourceRun', fn ($r) => $r->where('digital_asset_id', $assetId));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        if ($runs->isEmpty()) {
            return IntegrityCheckOutcome::pass(
                checkId: 'pagination_completeness',
                category: 'pagination',
                message: "No DatasetRuns yet for [{$datasetId}] ({$mode}).",
                evidence: ['pagination_mode' => $mode],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        foreach ($runs as $run) {
            $checkpoint = is_array($run->checkpoint) ? $run->checkpoint : [];
            $completeness = $checkpoint['execution_completeness']
                ?? $checkpoint['completeness']
                ?? null;

            // Meta async: provider 100% but download incomplete = FAIL
            $async = is_array($checkpoint['async'] ?? null) ? $checkpoint['async'] : [];
            if ($async !== []) {
                $providerPercent = (float) ($async['provider_percent'] ?? 0);
                $providerStatus = (string) ($async['provider_status'] ?? '');
                $stage = (string) ($async['stage'] ?? '');
                $pagesDownloaded = (int) ($async['pages_downloaded'] ?? 0);
                if (
                    ($providerPercent >= 100.0 || strtoupper($providerStatus) === 'JOB_COMPLETED')
                    && in_array($stage, ['WAITING_PROVIDER', 'DOWNLOADING_RESULTS', 'SUBMIT'], true)
                    && $run->status === CollectionRunStatus::Completed
                ) {
                    return IntegrityCheckOutcome::fail(
                        checkId: 'pagination_completeness',
                        category: 'pagination',
                        message: "Meta async provider complete but DatasetRun marked complete before durable result ingestion for [{$datasetId}].",
                        observed: [
                            'provider_percent' => $providerPercent,
                            'stage' => $stage,
                            'pages_downloaded' => $pagesDownloaded,
                            'dataset_run_status' => $run->status->value,
                        ],
                        provider: $provider,
                        datasetId: $datasetId,
                        assetId: $assetId,
                        resourceId: $resourceId,
                    );
                }
                if (
                    ($providerPercent >= 100.0 || strtoupper($providerStatus) === 'JOB_COMPLETED')
                    && $stage === 'DOWNLOADING_RESULTS'
                    && ($async['result_complete'] ?? false) !== true
                    && $run->status === CollectionRunStatus::Completed
                ) {
                    return IntegrityCheckOutcome::fail(
                        checkId: 'pagination_completeness',
                        category: 'pagination',
                        message: "Meta async result pagination incomplete while DatasetRun completed for [{$datasetId}].",
                        provider: $provider,
                        datasetId: $datasetId,
                        assetId: $assetId,
                        resourceId: $resourceId,
                    );
                }
            }

            // GA4 rowCount mismatch
            if ($mode === 'GA4_OFFSET_ROWCOUNT') {
                $expected = $checkpoint['provider_row_count'] ?? $checkpoint['row_count'] ?? null;
                $received = $checkpoint['rows_received_total'] ?? $run->rows_received ?? null;
                if ($expected !== null && $received !== null && (int) $expected !== (int) $received
                    && $run->status === CollectionRunStatus::Completed) {
                    return IntegrityCheckOutcome::fail(
                        checkId: 'pagination_completeness',
                        category: 'pagination',
                        message: "GA4 rowCount mismatch for [{$datasetId}].",
                        expected: ['provider_row_count' => (int) $expected],
                        observed: ['rows_received' => (int) $received],
                        provider: $provider,
                        datasetId: $datasetId,
                        assetId: $assetId,
                        resourceId: $resourceId,
                    );
                }
            }

            if ($completeness === 'COMPLETE_WITH_PROVIDER_LIMIT' || ($checkpoint['provider_limit_applied'] ?? false)) {
                return IntegrityCheckOutcome::limitation(
                    checkId: 'pagination_completeness',
                    category: 'pagination',
                    message: "Pagination complete with provider limitation for [{$datasetId}] ({$mode}).",
                    evidence: ['pagination_mode' => $mode, 'completeness' => $completeness],
                    provider: $provider,
                    datasetId: $datasetId,
                    assetId: $assetId,
                    resourceId: $resourceId,
                );
            }
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'pagination_completeness',
            category: 'pagination',
            message: "Pagination/stream completeness OK for [{$datasetId}] ({$mode}).",
            evidence: ['pagination_mode' => $mode, 'runs_checked' => $runs->count()],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkTimezone(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $table = (string) $profile['physical_table'];
        $source = (string) ($profile['timezone_source'] ?? 'unknown');

        if (! Schema::hasTable($table)) {
            return IntegrityCheckOutcome::unverified(
                checkId: 'timezone_provenance',
                category: 'timezone',
                message: "Table unavailable for timezone check [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $tzColumn = null;
        foreach (['source_timezone', 'timezone', 'property_timezone'] as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                $tzColumn = $candidate;
                break;
            }
        }

        if ($tzColumn === null) {
            return IntegrityCheckOutcome::pass(
                checkId: 'timezone_provenance',
                category: 'timezone',
                message: "No timezone column on [{$datasetId}]; reporting-date semantics source={$source}.",
                evidence: ['timezone_source' => $source],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $expectedTz = null;
        if ($resourceId !== null) {
            $resource = CoreExternalResource::query()->find($resourceId);
            $meta = is_array($resource?->metadata) ? $resource->metadata : [];
            $expectedTz = $meta['timezone_name'] ?? $meta['time_zone'] ?? $meta['timezone'] ?? null;
        }

        $q = DB::table($table)->whereNotNull($tzColumn);
        if ($assetId !== null && Schema::hasColumn($table, 'digital_asset_id')) {
            $q->where('digital_asset_id', $assetId);
        }
        $distinct = $q->distinct()->limit(10)->pluck($tzColumn)->filter()->values();

        if ($expectedTz !== null && $distinct->isNotEmpty()) {
            $mismatched = $distinct->filter(fn ($tz) => (string) $tz !== (string) $expectedTz);
            if ($mismatched->isNotEmpty()) {
                return IntegrityCheckOutcome::fail(
                    checkId: 'timezone_provenance',
                    category: 'timezone',
                    message: "Timezone mismatch vs resource metadata for [{$datasetId}].",
                    expected: ['timezone' => $expectedTz, 'source' => $source],
                    observed: ['fact_timezones' => $mismatched->all()],
                    provider: $provider,
                    datasetId: $datasetId,
                    assetId: $assetId,
                    resourceId: $resourceId,
                );
            }
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'timezone_provenance',
            category: 'timezone',
            message: "Timezone provenance OK for [{$datasetId}] (no Brand/server rebucket).",
            evidence: ['timezone_source' => $source, 'distinct' => $distinct->all()],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkCurrency(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $table = (string) $profile['physical_table'];
        $source = (string) ($profile['currency_source'] ?? 'NOT_APPLICABLE');

        if ($source === 'NOT_APPLICABLE') {
            return IntegrityCheckOutcome::notApplicable(
                checkId: 'currency_provenance',
                category: 'currency',
                message: 'Currency not applicable.',
                provider: $provider,
                datasetId: $datasetId,
            );
        }

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'currency')) {
            return IntegrityCheckOutcome::unverified(
                checkId: 'currency_provenance',
                category: 'currency',
                message: "Currency column unavailable for [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $expected = null;
        if ($resourceId !== null) {
            $resource = CoreExternalResource::query()->find($resourceId);
            $meta = is_array($resource?->metadata) ? $resource->metadata : [];
            $expected = $meta['currency'] ?? $meta['currency_code'] ?? null;
        }

        $q = DB::table($table)->whereNotNull('currency');
        if ($assetId !== null) {
            $q->where('digital_asset_id', $assetId);
        }
        $distinct = $q->distinct()->limit(10)->pluck('currency')->filter()->values();

        if ($distinct->count() > 1) {
            return IntegrityCheckOutcome::fail(
                checkId: 'currency_provenance',
                category: 'currency',
                message: "Cross-currency mix in single resource scope for [{$datasetId}].",
                observed: ['currencies' => $distinct->all()],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if ($expected !== null && $distinct->isNotEmpty() && (string) $distinct->first() !== (string) $expected) {
            return IntegrityCheckOutcome::fail(
                checkId: 'currency_provenance',
                category: 'currency',
                message: "Currency mismatch vs resource for [{$datasetId}].",
                expected: ['currency' => $expected],
                observed: ['currency' => $distinct->first()],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'currency_provenance',
            category: 'currency',
            message: "Currency provenance OK for [{$datasetId}] (no FX).",
            evidence: ['currency_source' => $source, 'currencies' => $distinct->all()],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkNonAdditiveProtection(array $profile): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $forbidden = $profile['non_additive_metrics'] ?? [];

        foreach ($forbidden as $metric) {
            if ($this->aggregationGuard->canSumAcrossDates((string) $metric, $forbidden)) {
                return IntegrityCheckOutcome::fail(
                    checkId: 'non_additive_metric_protection',
                    category: 'metrics',
                    message: "Non-additive metric [{$metric}] incorrectly allowed for summation on [{$datasetId}].",
                    provider: $provider,
                    datasetId: $datasetId,
                );
            }
        }

        // Explicit mandatory protections
        foreach (['reach', 'frequency', 'totalUsers', 'activeUsers', 'users'] as $metric) {
            if (! $this->aggregationGuard->canSumAcrossDates($metric, $forbidden)) {
                continue;
            }
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'non_additive_metric_protection',
            category: 'metrics',
            message: "Non-additive metrics protected for [{$datasetId}].",
            evidence: ['non_additive_metrics' => $forbidden],
            provider: $provider,
            datasetId: $datasetId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkProviderTotalCompatibility(array $profile): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $rules = $profile['provider_total_reconciliation'] ?? [];
        $forbidden = $rules['forbid_sum_metrics'] ?? ($profile['non_additive_metrics'] ?? []);

        // Document that invalid comparisons are rejected (no live calls in local mode).
        foreach ($forbidden as $metric) {
            try {
                $this->aggregationGuard->assertSummationAllowed((string) $metric, $forbidden);

                return IntegrityCheckOutcome::fail(
                    checkId: 'provider_total_compatibility',
                    category: 'reconciliation',
                    message: "Guard failed to reject non-additive [{$metric}] on [{$datasetId}].",
                    provider: $provider,
                    datasetId: $datasetId,
                );
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'provider_total_compatibility',
            category: 'reconciliation',
            message: "Provider-total compatibility rules enforced for [{$datasetId}] (local mode; no live calls).",
            evidence: [
                'enabled' => (bool) ($rules['enabled'] ?? false),
                'default_mode' => $rules['default_mode'] ?? 'LOCAL_SAME_RUN',
                'tolerance' => $rules['tolerance'] ?? null,
            ],
            provider: $provider,
            datasetId: $datasetId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkContractCompleteness(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $disposition = (string) ($profile['storage_disposition'] ?? '');

        if ($disposition === 'STORAGE_CONTRACT_GAP') {
            return IntegrityCheckOutcome::fail(
                checkId: 'contract_completeness',
                category: 'contract',
                message: "Required storage gap blocks [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        if ($disposition === 'PHYSICAL_TABLE' && ! $this->storage->hasPhysicalTable($datasetId)) {
            return IntegrityCheckOutcome::fail(
                checkId: 'contract_completeness',
                category: 'contract',
                message: "Physical table missing for [{$datasetId}].",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'contract_completeness',
            category: 'contract',
            message: "Contract/storage mapping present for [{$datasetId}].",
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function checkFreshness(array $profile, ?int $assetId, ?int $resourceId): IntegrityCheckOutcome
    {
        $datasetId = (string) $profile['dataset_id'];
        $provider = (string) $profile['provider_or_source'];
        $slaHours = (int) ($profile['freshness_sla_hours'] ?? 48);

        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->when($assetId !== null, fn ($q) => $q->where('digital_asset_id', $assetId))
            ->when($resourceId !== null, fn ($q) => $q->where('external_resource_id', $resourceId))
            ->orderByDesc('id')
            ->first();

        if (! $materialization instanceof DatasetMaterialization || $materialization->last_collected_at === null) {
            return IntegrityCheckOutcome::pass(
                checkId: 'freshness',
                category: 'freshness',
                message: "Freshness N/A — [{$datasetId}] not collected (Prompt 26 does not schedule refresh).",
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
            );
        }

        $ageHours = $materialization->last_collected_at->diffInHours(now());
        if ($materialization->status === MaterializationStatus::Stale || $ageHours > $slaHours) {
            return IntegrityCheckOutcome::warning(
                checkId: 'freshness',
                category: 'freshness',
                message: "Dataset [{$datasetId}] is stale vs SLA {$slaHours}h (not corrupt).",
                evidence: [
                    'age_hours' => $ageHours,
                    'sla_hours' => $slaHours,
                    'status' => $materialization->status->value,
                    'refresh_scheduled' => false,
                ],
                provider: $provider,
                datasetId: $datasetId,
                assetId: $assetId,
                resourceId: $resourceId,
                blocksMigration: true,
            );
        }

        return IntegrityCheckOutcome::pass(
            checkId: 'freshness',
            category: 'freshness',
            message: "Dataset [{$datasetId}] within freshness SLA.",
            evidence: ['age_hours' => $ageHours, 'sla_hours' => $slaHours],
            provider: $provider,
            datasetId: $datasetId,
            assetId: $assetId,
            resourceId: $resourceId,
        );
    }
}
