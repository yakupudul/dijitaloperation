<?php

namespace App\Services\DataPool\Integrity;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads MOXDOP_DATA_INTEGRITY_REGISTRY_V1. Profiles reference Dataset IDs only.
 *
 * Meta Ads Professional V2 is a runtime data-contract overlay. Its new physical
 * datasets are therefore mirrored into the integrity registry at load time so
 * freshly collected V2 facts can pass the same REAL/PARTIAL_REAL UI gate as
 * legacy registry datasets without duplicating the entire registry JSON.
 */
final class DataIntegrityRegistryLoader
{
    private ?array $registry = null;

    /** @var array<string, array<string, mixed>> */
    private array $profilesByDataset = [];

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function registry(): array
    {
        $this->ensureLoaded();

        return $this->registry;
    }

    public function version(): int
    {
        return (int) ($this->registry()['metadata']['version'] ?? 0);
    }

    public function registryId(): string
    {
        return (string) ($this->registry()['metadata']['integrity_registry_id'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function globalPolicies(): array
    {
        return $this->registry()['global_policies'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function profiles(): array
    {
        return $this->registry()['dataset_profiles'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profile(string $datasetId): ?array
    {
        $this->ensureLoaded();

        return $this->profilesByDataset[$datasetId] ?? null;
    }

    /**
     * @param  list<string>|null  $providers
     * @return list<array<string, mixed>>
     */
    public function profilesForProviders(?array $providers = null): array
    {
        $profiles = $this->profiles();
        if ($providers === null || $providers === []) {
            return $profiles;
        }

        return array_values(array_filter(
            $profiles,
            static fn (array $p): bool => in_array((string) ($p['provider_or_source'] ?? ''), $providers, true),
        ));
    }

    private function ensureLoaded(): void
    {
        if ($this->registry !== null) {
            return;
        }

        $path = $this->path ?? config('moxdop-data-integrity.integrity_registry_path');
        if (! is_string($path) || ! File::exists($path)) {
            throw new RuntimeException('Data integrity registry not found at '.$path);
        }

        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $meta = $decoded['metadata'] ?? [];
        if (($meta['integrity_registry_id'] ?? null) !== config('moxdop-data-integrity.integrity_registry_id')) {
            throw new RuntimeException('Unsupported integrity_registry_id');
        }
        $version = (int) ($meta['version'] ?? 0);
        if (! in_array($version, config('moxdop-data-integrity.supported_integrity_registry_versions'), true)) {
            throw new RuntimeException("Unsupported integrity registry version [{$version}]");
        }

        if (($decoded['global_policies']['numeric_quality_score'] ?? null) !== false) {
            throw new RuntimeException('Integrity registry must disable numeric_quality_score');
        }
        if (($decoded['global_policies']['automatic_repair'] ?? null) !== false) {
            throw new RuntimeException('Integrity registry must disable automatic_repair');
        }

        $decoded['dataset_profiles'] = $this->withMetaAdsProfessionalProfiles(
            is_array($decoded['dataset_profiles'] ?? null) ? $decoded['dataset_profiles'] : [],
        );

        $this->registry = $decoded;
        foreach ($decoded['dataset_profiles'] ?? [] as $profile) {
            $this->profilesByDataset[(string) $profile['dataset_id']] = $profile;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return list<array<string, mixed>>
     */
    private function withMetaAdsProfessionalProfiles(array $profiles): array
    {
        $families = config('moxdop-meta-ads-central.families', []);
        $physical = config('moxdop-meta-ads-central.physical_additions', []);

        if (! is_array($families) || ! is_array($physical) || $families === [] || $physical === []) {
            return $profiles;
        }

        $known = [];
        foreach ($profiles as $profile) {
            if (is_array($profile) && isset($profile['dataset_id'])) {
                $known[(string) $profile['dataset_id']] = true;
            }
        }

        foreach ($families as $familyId => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $datasetId = (string) ($definition['dataset'] ?? '');
            if ($datasetId === '' || isset($known[$datasetId])) {
                continue;
            }

            $contract = $physical[$datasetId] ?? null;
            if (! is_array($contract)) {
                continue;
            }

            $history = (string) ($definition['history'] ?? '');
            $storageClass = (string) ($contract['storage_class'] ?? '');
            $isSnapshot = $history === 'current' || $storageClass === 'NORMALIZED_SNAPSHOT';
            $metricColumns = $this->metricColumns($contract);
            $nonAdditive = array_values(array_intersect($metricColumns, ['reach', 'frequency']));
            $additive = array_values(array_diff($metricColumns, $nonAdditive));

            // Meta Professional V2 separates two concerns deliberately:
            // 1) integrity audit answers whether persisted facts are structurally trustworthy;
            // 2) MetaAdsUiDatasetGate evaluates requested date-range coverage independently.
            //
            // Do not run the generic checkpoint/write-receipt check here yet: the legacy
            // checker scans unrelated family runs and can report false checkpoint-ahead
            // failures for V2 datasets. The canonical writer still persists receipts and
            // row_accounting validates committed V2 batches where applicable.
            $requiredChecks = [
                'natural_key_duplicates',
                'referential_integrity',
                'provenance',
                'materialization_reconciliation',
                'contract_completeness',
                'freshness',
                'timezone_provenance',
            ];

            if ($nonAdditive !== []) {
                $requiredChecks[] = 'non_additive_metric_protection';
            }

            if ($isSnapshot) {
                $requiredChecks[] = 'snapshot_semantics';
            } else {
                // Range coverage remains encoded on DatasetMaterialization and is enforced
                // by MetaAdsUiDatasetGate for the actual user-selected period. A historical
                // gap outside that period must not invalidate otherwise trustworthy facts.
                // Video normalization may emit multiple metric rows from one provider row,
                // so strict received===written row accounting would be semantically wrong.
                if ($datasetId !== 'meta_video_engagement_daily') {
                    $requiredChecks[] = 'row_accounting';
                }
            }

            $blockingChecks = [
                'natural_key_duplicates',
                'referential_integrity',
                'materialization_reconciliation',
                'contract_completeness',
                'timezone_provenance',
            ];

            $profiles[] = [
                'dataset_id' => $datasetId,
                'provider_or_source' => 'META_ADS',
                'storage_disposition' => 'PHYSICAL_TABLE',
                'physical_table' => (string) ($contract['table'] ?? $datasetId),
                'grain' => array_values((array) ($contract['grain'] ?? [])),
                'natural_key' => array_values((array) ($contract['natural_key'] ?? [])),
                'collection_run_in_natural_key' => false,
                'history_mode' => $isSnapshot ? 'snapshot' : 'historical',
                'coverage_mode' => $isSnapshot ? 'SNAPSHOT' : 'INTERVAL_SET',
                'pagination_mode' => 'META_CURSOR_BOUNDED',
                'row_accounting_mode' => $isSnapshot ? 'SNAPSHOT_UPSERT' : 'ONE_TO_ONE',
                'timezone_source' => 'meta_ad_account_timezone',
                'currency_source' => $metricColumns !== [] ? 'meta_ad_account_currency' : 'NOT_APPLICABLE',
                'raw_required' => false,
                'request_family_ids' => [(string) $familyId],
                'additive_metrics' => $additive,
                'non_additive_metrics' => $nonAdditive,
                'freshness_sla_hours' => $isSnapshot ? 168 : 48,
                'required_checks' => array_values(array_unique($requiredChecks)),
                'migration_blocking_checks' => array_values(array_unique($blockingChecks)),
                'provider_total_reconciliation' => [
                    'enabled' => false,
                    'default_mode' => 'LOCAL_SAME_RUN',
                    'forbid_sum_metrics' => $nonAdditive,
                    'tolerance' => null,
                ],
                'metadata' => [
                    'runtime_overlay' => 'META_ADS_PROFESSIONAL_V2',
                    'source_contract' => 'config/moxdop-meta-ads-central.php',
                    'coverage_gate' => 'MetaAdsUiDatasetGate',
                ],
            ];
            $known[$datasetId] = true;
        }

        return $profiles;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private function metricColumns(array $contract): array
    {
        $metrics = [];
        foreach ((array) ($contract['columns'] ?? []) as $column) {
            if (! is_array($column) || ($column['role'] ?? null) !== 'metric') {
                continue;
            }
            $name = (string) ($column['name'] ?? '');
            if ($name !== '') {
                $metrics[] = $name;
            }
        }

        return array_values(array_unique($metrics));
    }
}
