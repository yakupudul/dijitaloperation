<?php

namespace App\Services\DataPool\Integrity;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads MOXDOP_DATA_INTEGRITY_REGISTRY_V1 and applies typed runtime overlays.
 */
final class DataIntegrityRegistryLoader
{
    private ?array $registry = null;

    /** @var array<string, array<string, mixed>> */
    private array $profilesByDataset = [];

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    public function globalPolicies(): array
    {
        return $this->registry()['global_policies'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function profiles(): array
    {
        return $this->registry()['dataset_profiles'] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function profile(string $datasetId): ?array
    {
        $this->ensureLoaded();

        return $this->profilesByDataset[$datasetId] ?? null;
    }

    /**
     * @param list<string>|null $providers
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

        $profiles = is_array($decoded['dataset_profiles'] ?? null) ? $decoded['dataset_profiles'] : [];
        $profiles = $this->withMetaAdsProfessionalProfiles($profiles);
        $decoded['dataset_profiles'] = $this->withWebsiteIntelligenceProfiles($profiles);

        $this->registry = $decoded;
        foreach ($decoded['dataset_profiles'] ?? [] as $profile) {
            $this->profilesByDataset[(string) $profile['dataset_id']] = $profile;
        }
    }

    /**
     * @param list<array<string, mixed>> $profiles
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
            } elseif ($datasetId !== 'meta_video_engagement_daily') {
                $requiredChecks[] = 'row_accounting';
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

        $professionalDatasetIds = [];
        foreach ($families as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $datasetId = (string) ($definition['dataset'] ?? '');
            if ($datasetId !== '') {
                $professionalDatasetIds[$datasetId] = true;
            }
        }

        foreach ($profiles as &$profile) {
            if (! is_array($profile)) {
                continue;
            }

            $datasetId = (string) ($profile['dataset_id'] ?? '');
            if (! isset($professionalDatasetIds[$datasetId])) {
                continue;
            }

            $profile['required_checks'] = array_values(array_filter(
                array_values((array) ($profile['required_checks'] ?? [])),
                static fn (mixed $check): bool => ! in_array((string) $check, ['write_receipt_accounting', 'coverage_intervals'], true),
            ));
            $profile['migration_blocking_checks'] = array_values(array_filter(
                array_values((array) ($profile['migration_blocking_checks'] ?? [])),
                static fn (mixed $check): bool => ! in_array((string) $check, ['write_receipt_accounting', 'coverage_intervals'], true),
            ));
            $profile['metadata'] = array_merge(
                is_array($profile['metadata'] ?? null) ? $profile['metadata'] : [],
                ['professional_v2_policy' => true, 'coverage_gate' => 'MetaAdsUiDatasetGate'],
            );
        }
        unset($profile);

        return $profiles;
    }

    /**
     * Mirror Website Intelligence runtime physical additions into integrity auditing.
     * These are current-state crawl/CMS observations; a failed integrity check must never
     * be auto-repaired or silently converted into an SEO score.
     *
     * @param list<array<string, mixed>> $profiles
     * @return list<array<string, mixed>>
     */
    private function withWebsiteIntelligenceProfiles(array $profiles): array
    {
        $physical = config('moxdop-website-intelligence.physical_additions', []);
        $families = config('moxdop-website-intelligence.integrity_request_families', []);
        if (! is_array($physical) || $physical === []) {
            return $profiles;
        }

        $known = [];
        foreach ($profiles as $profile) {
            if (is_array($profile) && isset($profile['dataset_id'])) {
                $known[(string) $profile['dataset_id']] = true;
            }
        }

        foreach ($physical as $datasetId => $contract) {
            if (! is_array($contract) || isset($known[$datasetId])) {
                continue;
            }

            $provider = (string) ($contract['provider_or_source'] ?? 'WEBSITE_DIRECT');
            $requestFamilies = is_array($families[$datasetId] ?? null) ? array_values($families[$datasetId]) : [];

            $profiles[] = [
                'dataset_id' => (string) $datasetId,
                'provider_or_source' => $provider,
                'storage_disposition' => 'PHYSICAL_TABLE',
                'physical_table' => (string) ($contract['table'] ?? $datasetId),
                'grain' => array_values((array) ($contract['grain'] ?? [])),
                'natural_key' => array_values((array) ($contract['natural_key'] ?? [])),
                'collection_run_in_natural_key' => false,
                'history_mode' => 'snapshot',
                'coverage_mode' => 'SNAPSHOT',
                'pagination_mode' => $datasetId === 'website_cms_object_snapshot' ? 'WP_REST_PAGED' : 'WEBSITE_CRAWL_BOUNDED',
                'row_accounting_mode' => 'SNAPSHOT_UPSERT',
                'timezone_source' => 'UTC',
                'currency_source' => 'NOT_APPLICABLE',
                'raw_required' => false,
                'request_family_ids' => $requestFamilies,
                'additive_metrics' => [],
                'non_additive_metrics' => [],
                'freshness_sla_hours' => 168,
                'required_checks' => [
                    'natural_key_duplicates',
                    'referential_integrity',
                    'provenance',
                    'materialization_reconciliation',
                    'contract_completeness',
                    'freshness',
                    'timezone_provenance',
                    'snapshot_semantics',
                ],
                'migration_blocking_checks' => [
                    'natural_key_duplicates',
                    'referential_integrity',
                    'materialization_reconciliation',
                    'contract_completeness',
                    'timezone_provenance',
                ],
                'provider_total_reconciliation' => [
                    'enabled' => false,
                    'default_mode' => 'LOCAL_SAME_RUN',
                    'forbid_sum_metrics' => [],
                    'tolerance' => null,
                ],
                'metadata' => [
                    'runtime_overlay' => 'WEBSITE_INTELLIGENCE_V1',
                    'source_contract' => 'config/moxdop-website-intelligence.php',
                ],
            ];
            $known[(string) $datasetId] = true;
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $contract
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
