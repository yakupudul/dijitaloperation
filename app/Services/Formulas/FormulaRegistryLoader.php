<?php

namespace App\Services\Formulas;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads MOXDOP_FORMULA_REGISTRY_V1. Formula calculators reference Formula IDs only —
 * no inline blade formulas, no per-specialist reinvention of missing/rounding policy.
 */
final class FormulaRegistryLoader
{
    /** @var array<string, mixed>|null */
    private ?array $registry = null;

    /** @var array<string, array<string, mixed>> */
    private array $formulasById = [];

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
        return (string) ($this->registry()['metadata']['registry_id'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function globalPolicies(): array
    {
        return $this->registry()['global_policies'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function formula(string $formulaId): array
    {
        $this->ensureLoaded();

        if (! isset($this->formulasById[$formulaId])) {
            throw new RuntimeException("Unknown formula id [{$formulaId}] — formulas are not invented ad hoc.");
        }

        return $this->formulasById[$formulaId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function roundingPolicy(string $policyId): ?array
    {
        foreach ($this->registry()['rounding_policies'] ?? [] as $policy) {
            if (($policy['id'] ?? null) === $policyId) {
                return $policy;
            }
        }

        return null;
    }

    private function ensureLoaded(): void
    {
        if ($this->registry !== null) {
            return;
        }

        $path = $this->path ?? (string) config('moxdop-formulas.formula_registry_path');
        if ($path === '' || ! File::exists($path)) {
            throw new RuntimeException("Formula registry not found at [{$path}].");
        }

        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $registryId = (string) ($decoded['metadata']['registry_id'] ?? '');
        if ($registryId !== (string) config('moxdop-formulas.formula_registry_id')) {
            throw new RuntimeException("Unsupported formula registry id [{$registryId}].");
        }

        $version = (int) ($decoded['metadata']['version'] ?? 0);
        $supported = config('moxdop-formulas.supported_formula_registry_versions', [1]);
        if (! in_array($version, $supported, true)) {
            throw new RuntimeException("Unsupported formula registry version [{$version}].");
        }

        $policies = $decoded['global_policies'] ?? [];
        if (($policies['MISSING_NEVER_EQUALS_ZERO'] ?? false) !== true) {
            throw new RuntimeException('Formula registry must enforce MISSING_NEVER_EQUALS_ZERO.');
        }
        if (($policies['NO_SILENT_DIVIDE_BY_ZERO'] ?? false) !== true) {
            throw new RuntimeException('Formula registry must enforce NO_SILENT_DIVIDE_BY_ZERO.');
        }

        $this->registry = $decoded;
        foreach ($decoded['formulas'] ?? [] as $formula) {
            if (! is_array($formula)) {
                continue;
            }
            $id = (string) ($formula['id'] ?? '');
            if ($id !== '') {
                $this->formulasById[$id] = $formula;
            }
        }
    }
}
