<?php

namespace App\Services\Collection;

use InvalidArgumentException;
use RuntimeException;

/**
 * Safe runtime reader for MOXDOP_DATA_CONTRACT_REGISTRY_V1.json.
 * Collectors do not invent requirements — they consume this registry.
 */
final class DataContractRegistryLoader
{
    /** @var array<string, mixed>|null */
    private ?array $registry = null;

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $path = $this->path ?? (string) config('moxdop-collection.registry_path');
        if (! is_file($path)) {
            throw new RuntimeException("Data Contract Registry file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException("Data Contract Registry file unreadable: {$path}");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Data Contract Registry JSON invalid: '.$e->getMessage(), 0, $e);
        }

        $this->assertValid($decoded);

        $this->registry = $decoded;

        return $this->registry;
    }

    public function checksum(): string
    {
        $path = $this->path ?? (string) config('moxdop-collection.registry_path');
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Unable to checksum registry: {$path}");
        }

        return hash('sha256', $raw);
    }

    public function version(): int
    {
        /** @var array{metadata: array{version: int}} $registry */
        $registry = $this->load();

        return (int) $registry['metadata']['version'];
    }

    public function registryId(): string
    {
        /** @var array{metadata: array{registry_id: string}} $registry */
        $registry = $this->load();

        return (string) $registry['metadata']['registry_id'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function datasets(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->load()['datasets'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requestFamilies(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->load()['request_families'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requirements(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->load()['requirements'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dataset(string $id): ?array
    {
        foreach ($this->datasets() as $dataset) {
            if (($dataset['id'] ?? null) === $id) {
                return $dataset;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function requestFamily(string $id): ?array
    {
        foreach ($this->requestFamilies() as $family) {
            if (($family['id'] ?? null) === $id) {
                return $family;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function assertValid(array $decoded): void
    {
        $metadata = $decoded['metadata'] ?? null;
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('Registry metadata missing');
        }

        $registryId = (string) ($metadata['registry_id'] ?? '');
        $expectedId = (string) config('moxdop-collection.registry_id');
        if ($registryId !== $expectedId) {
            throw new InvalidArgumentException("Unsupported registry_id: {$registryId}");
        }

        $version = (int) ($metadata['version'] ?? 0);
        $supported = config('moxdop-collection.supported_registry_versions', [1]);
        if (! in_array($version, $supported, true)) {
            throw new InvalidArgumentException("Unsupported registry version: {$version}");
        }

        foreach (['requirements', 'datasets', 'request_families'] as $key) {
            if (! isset($decoded[$key]) || ! is_array($decoded[$key])) {
                throw new InvalidArgumentException("Registry missing {$key}");
            }
        }
    }
}
