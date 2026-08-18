<?php

namespace App\Services\DataPool\Integrity;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads MOXDOP_DATA_INTEGRITY_REGISTRY_V1. Profiles reference Dataset IDs only.
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

        $this->registry = $decoded;
        foreach ($decoded['dataset_profiles'] ?? [] as $profile) {
            $this->profilesByDataset[(string) $profile['dataset_id']] = $profile;
        }
    }
}
