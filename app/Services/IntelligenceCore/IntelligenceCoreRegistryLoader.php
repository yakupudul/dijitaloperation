<?php

namespace App\Services\IntelligenceCore;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

final class IntelligenceCoreRegistryLoader
{
    /** @var array<string, mixed>|null */
    private ?array $registry = null;

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /** @return array<string, mixed> */
    public function registry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $path = $this->path ?? (string) config('moxdop-intelligence-core.registry_path');
        if ($path === '' || ! File::exists($path)) {
            throw new RuntimeException("Intelligence Core registry not found at [{$path}].");
        }

        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Intelligence Core registry must decode to an object.');
        }

        $this->assertValid($decoded);
        $this->registry = $decoded;

        return $this->registry;
    }

    public function version(): int
    {
        return (int) ($this->registry()['metadata']['version'] ?? 0);
    }

    public function registryId(): string
    {
        return (string) ($this->registry()['metadata']['registry_id'] ?? '');
    }

    public function checksum(): string
    {
        return hash('sha256', json_encode(
            $this->registry(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function profiles(): array
    {
        return array_values($this->registry()['profiles'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function dimensions(): array
    {
        return array_values($this->registry()['dimensions'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function sourceClasses(): array
    {
        return array_values($this->registry()['source_classes'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function capabilities(): array
    {
        return array_values($this->registry()['capabilities'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function sources(): array
    {
        return array_values($this->registry()['sources'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function metrics(): array
    {
        return array_values($this->registry()['metrics'] ?? []);
    }

    /** @param array<string, mixed> $registry */
    private function assertValid(array $registry): void
    {
        $registryId = (string) ($registry['metadata']['registry_id'] ?? '');
        if ($registryId !== (string) config('moxdop-intelligence-core.registry_id')) {
            throw new InvalidArgumentException("Unsupported Intelligence Core registry id [{$registryId}].");
        }

        $version = (int) ($registry['metadata']['version'] ?? 0);
        $supported = config('moxdop-intelligence-core.supported_registry_versions', [1]);
        if (! is_array($supported) || ! in_array($version, $supported, true)) {
            throw new InvalidArgumentException("Unsupported Intelligence Core registry version [{$version}].");
        }

        foreach (['profiles', 'dimensions', 'source_classes', 'capabilities', 'sources', 'metrics'] as $section) {
            if (! is_array($registry[$section] ?? null)) {
                throw new InvalidArgumentException("Intelligence Core registry section [{$section}] is missing.");
            }
            $this->assertUniqueIds($section, $registry[$section]);
        }

        $policies = $registry['global_policies'] ?? [];
        foreach ([
            'PROVIDER_FACT_TABLES_REMAIN_CANONICAL',
            'NO_GENERIC_METRIC_WAREHOUSE',
            'MISSING_NEVER_EQUALS_ZERO',
            'ESTIMATED_NEVER_EQUALS_MEASURED',
            'PROJECTIONS_ARE_REBUILDABLE',
            'NO_MAGIC_SCORES',
        ] as $requiredPolicy) {
            if (($policies[$requiredPolicy] ?? false) !== true) {
                throw new InvalidArgumentException("Intelligence Core policy [{$requiredPolicy}] must be enabled.");
            }
        }

        $profileIds = $this->ids($registry['profiles']);
        $capabilityIds = $this->ids($registry['capabilities']);
        $sourceClassIds = $this->ids($registry['source_classes']);
        $sourceIds = $this->ids($registry['sources']);

        foreach ($registry['capabilities'] as $capability) {
            foreach ($capability['profiles'] ?? [] as $profileId) {
                if (! isset($profileIds[(string) $profileId])) {
                    throw new InvalidArgumentException("Capability references unknown profile [{$profileId}].");
                }
            }
        }

        foreach ($registry['sources'] as $source) {
            foreach ($source['capabilities'] ?? [] as $capabilityId) {
                if (! isset($capabilityIds[(string) $capabilityId])) {
                    throw new InvalidArgumentException("Source references unknown capability [{$capabilityId}].");
                }
            }
        }

        foreach ($registry['metrics'] as $metric) {
            $source = (string) ($metric['source'] ?? '');
            $sourceClass = (string) ($metric['source_class'] ?? '');
            if (! isset($sourceIds[$source])) {
                throw new InvalidArgumentException("Metric references unknown source [{$source}].");
            }
            if (! isset($sourceClassIds[$sourceClass])) {
                throw new InvalidArgumentException("Metric references unknown source class [{$sourceClass}].");
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertUniqueIds(string $section, array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? (string) ($row['id'] ?? '') : '';
            if ($id === '') {
                throw new InvalidArgumentException("Intelligence Core [{$section}] contains a row without id.");
            }
            if (isset($seen[$id])) {
                throw new InvalidArgumentException("Duplicate Intelligence Core id [{$id}] in [{$section}].");
            }
            $seen[$id] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, true>
     */
    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row['id']] = true;
        }

        return $ids;
    }
}
