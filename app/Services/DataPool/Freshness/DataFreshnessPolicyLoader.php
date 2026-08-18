<?php

namespace App\Services\DataPool\Freshness;

use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

/**
 * Loads MOXDOP_DATA_FRESHNESS_POLICY_V1. Policies reference Dataset IDs only.
 */
final class DataFreshnessPolicyLoader
{
    private ?array $registry = null;

    /** @var array<string, array<string, mixed>> */
    private array $policiesByDataset = [];

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
        return (string) ($this->registry()['metadata']['freshness_policy_registry_id'] ?? '');
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
    public function policies(): array
    {
        return $this->registry()['dataset_policies'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function policy(string $datasetId): ?array
    {
        $this->ensureLoaded();

        return $this->policiesByDataset[$datasetId] ?? null;
    }

    /**
     * @param  list<string>|null  $providers
     * @return list<array<string, mixed>>
     */
    public function policiesForProviders(?array $providers = null): array
    {
        $policies = $this->policies();
        if ($providers === null || $providers === []) {
            return $policies;
        }

        return array_values(array_filter(
            $policies,
            static fn (array $p): bool => in_array((string) ($p['provider_or_source'] ?? ''), $providers, true),
        ));
    }

    public function validate(): void
    {
        $this->ensureLoaded();

        $schemaPath = base_path('docs/data-contracts/MOXDOP_DATA_FRESHNESS_POLICY_V1.schema.json');
        if (! File::exists($schemaPath)) {
            throw new RuntimeException('Freshness policy schema missing.');
        }

        if (($this->registry['metadata']['numeric_freshness_score'] ?? true) !== false) {
            throw new RuntimeException('Freshness policy must forbid numeric freshness scores.');
        }

        if (($this->registry['metadata']['global_last_sync_forbidden'] ?? false) !== true) {
            throw new RuntimeException('Freshness policy must forbid a global last-sync truth.');
        }

        if (($this->registry['metadata']['global_reprocess_window_forbidden'] ?? false) !== true) {
            throw new RuntimeException('Freshness policy must forbid a global reprocess window.');
        }

        $ids = [];
        foreach ($this->policies() as $policy) {
            $id = (string) ($policy['dataset_id'] ?? '');
            if ($id === '') {
                throw new RuntimeException('Freshness policy entry missing dataset_id.');
            }
            if (isset($ids[$id])) {
                throw new RuntimeException("Duplicate freshness policy for [{$id}].");
            }
            $ids[$id] = true;

            if (! array_key_exists('incremental_applicable', $policy)) {
                throw new RuntimeException("Freshness policy [{$id}] missing incremental_applicable.");
            }

            if ($policy['incremental_applicable'] === false && empty($policy['non_applicable_reason'])) {
                throw new RuntimeException("Non-applicable freshness policy [{$id}] needs an explicit reason.");
            }
        }
    }

    private function ensureLoaded(): void
    {
        if ($this->registry !== null) {
            return;
        }

        $path = $this->path ?? (string) config(
            'moxdop-data-freshness.freshness_policy_registry_path',
            base_path('docs/data-contracts/MOXDOP_DATA_FRESHNESS_POLICY_V1.json')
        );

        if (! File::exists($path)) {
            throw new RuntimeException("Freshness policy registry not found at [{$path}].");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Freshness policy registry JSON invalid: '.$e->getMessage(), 0, $e);
        }

        $supported = config('moxdop-data-freshness.supported_freshness_policy_versions', [1]);
        $version = (int) ($decoded['metadata']['version'] ?? 0);
        if (! in_array($version, $supported, true)) {
            throw new RuntimeException("Unsupported freshness policy version [{$version}].");
        }

        $this->registry = $decoded;
        foreach ($decoded['dataset_policies'] ?? [] as $policy) {
            if (! is_array($policy)) {
                continue;
            }
            $id = (string) ($policy['dataset_id'] ?? '');
            if ($id !== '') {
                $this->policiesByDataset[$id] = $policy;
            }
        }
    }
}
