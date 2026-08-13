<?php

namespace App\Services\DataPool;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Validates MOXDOP_DATA_POOL_STORAGE_V1 against Data Contract Registry + Formula Registry.
 */
final class StorageContractValidator
{
    /**
     * @return list<string>
     */
    public function validate(
        ?string $storagePath = null,
        ?string $dataContractPath = null,
        ?string $formulaPath = null,
    ): array {
        $storagePath ??= config('moxdop-data-pool.storage_contract_path');
        $dataContractPath ??= config('moxdop-collection.registry_path');
        $formulaPath ??= base_path('docs/data-contracts/MOXDOP_FORMULA_REGISTRY_V1.json');

        $errors = [];
        $storage = $this->loadJson($storagePath);
        $dcr = $this->loadJson($dataContractPath);
        $formulas = File::exists($formulaPath) ? $this->loadJson($formulaPath) : null;

        $dcrIds = [];
        foreach ($dcr['datasets'] ?? [] as $ds) {
            $dcrIds[$ds['id']] = $ds;
        }

        $dispositionIds = [];
        foreach ($storage['dispositions'] as $row) {
            $id = $row['logical_dataset_id'];
            if (isset($dispositionIds[$id])) {
                $errors[] = "Duplicate disposition for [{$id}]";
            }
            $dispositionIds[$id] = $row;
            if (! isset($dcrIds[$id])) {
                $errors[] = "Disposition references unknown DCR dataset [{$id}]";
            }
        }

        foreach ($dcrIds as $id => $_) {
            if (! isset($dispositionIds[$id])) {
                $errors[] = "Missing storage disposition for DCR dataset [{$id}]";
            }
        }

        $tables = [];
        foreach ($storage['physical_datasets'] as $phys) {
            $id = $phys['logical_dataset_id'];
            $table = $phys['table'];
            if (! isset($dispositionIds[$id]) || ($dispositionIds[$id]['disposition'] ?? null) !== 'PHYSICAL_TABLE') {
                $errors[] = "Physical dataset [{$id}] lacks PHYSICAL_TABLE disposition";
            }
            if (isset($tables[$table])) {
                $errors[] = "Duplicate physical table mapping [{$table}]";
            }
            $tables[$table] = $id;

            if (! isset($dcrIds[$id])) {
                $errors[] = "Physical mapping references unknown dataset [{$id}]";
            }

            $dcrRow = $dcrIds[$id] ?? null;
            if ($dcrRow && ($dcrRow['storage_class'] ?? null) === 'DERIVED_RUNTIME') {
                $errors[] = "DERIVED_RUNTIME dataset [{$id}] must not have a physical fact table";
            }

            $nk = $phys['natural_key'] ?? [];
            if ($nk === []) {
                $errors[] = "Natural key empty for [{$id}]";
            }
            if (in_array('collection_run_id', $nk, true) || in_array('last_collection_run_id', $nk, true)) {
                $errors[] = "CollectionRun must not define fact identity for [{$id}]";
            }

            $colNames = array_column($phys['columns'] ?? [], 'name');
            foreach ($nk as $keyCol) {
                if (! in_array($keyCol, $colNames, true)) {
                    $errors[] = "Natural key column [{$keyCol}] missing from [{$id}]";
                }
            }

            if (($phys['partition_strategy'] ?? 'NONE') === 'RANGE_MONTHLY') {
                $partCol = $phys['partition_column'] ?? null;
                if ($partCol === null || ! in_array($partCol, $colNames, true)) {
                    $errors[] = "Partition column missing/invalid for [{$id}]";
                }
                if ($partCol !== null && ! in_array($partCol, $nk, true)) {
                    $errors[] = "Partition column [{$partCol}] must be in natural key for [{$id}] (PostgreSQL)";
                }
            }

            foreach ($phys['indexes'] ?? [] as $idx) {
                foreach ($idx['columns'] as $c) {
                    if (! in_array($c, $colNames, true)) {
                        $errors[] = "Index column [{$c}] missing on [{$id}]";
                    }
                }
            }

            foreach ($phys['columns'] ?? [] as $col) {
                if (($col['role'] ?? null) === 'base_metric' && ! in_array($col['name'], $colNames, true)) {
                    $errors[] = "Base metric column missing on [{$id}]";
                }
            }

            $writeMode = $phys['write_mode'] ?? null;
            $allowed = $storage['enums']['write_mode'] ?? [];
            if ($writeMode !== null && $allowed !== [] && ! in_array($writeMode, $allowed, true)) {
                $errors[] = "Invalid write_mode [{$writeMode}] for [{$id}]";
            }
        }

        if ($formulas !== null) {
            foreach ($formulas['formulas'] ?? [] as $formula) {
                foreach ($formula['inputs'] ?? [] as $input) {
                    if (! is_array($input)) {
                        continue;
                    }
                    $dataset = $input['dataset'] ?? $input['logical_dataset_id'] ?? $input['dataset_id'] ?? null;
                    if (! is_string($dataset) || $dataset === '') {
                        continue;
                    }
                    $disp = $dispositionIds[$dataset]['disposition'] ?? null;
                    if ($disp === null) {
                        // Formula may reference requirement IDs rather than datasets — skip unknown.
                        continue;
                    }
                    if ($disp === 'STORAGE_CONTRACT_GAP') {
                        $errors[] = "STORAGE CONTRACT GAP: formula [{$formula['id']}] needs [{$dataset}]";
                    }
                    if ($disp === 'DERIVED_RUNTIME') {
                        $errors[] = "Formula [{$formula['id']}] unexpectedly maps input [{$dataset}] as DERIVED_RUNTIME physical source";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path): array
    {
        if (! File::exists($path)) {
            throw new RuntimeException("JSON not found: {$path}");
        }

        return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
