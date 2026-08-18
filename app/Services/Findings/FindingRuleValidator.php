<?php

namespace App\Services\Findings;

use App\Services\Evidence\EvidenceDefinitionRegistry;
use RuntimeException;

/**
 * Validates Finding rules against Evidence Definition Registry, typed operators, and clear/auto-resolve invariants.
 */
final class FindingRuleValidator
{
    /** @var list<string> */
    private const array CONDITION_TYPES = [
        'VALUE_LT',
        'VALUE_LTE',
        'VALUE_GT',
        'VALUE_GTE',
        'VALUE_EQUALS',
        'VALUE_BETWEEN',
        'VALUE_OUTSIDE',
        'STATE_EQUALS',
        'STATE_NOT_EQUALS',
        'BOOLEAN_IS',
        'ABSENCE_CONFIRMED',
        'PRESENCE_CONFIRMED',
        'ABS_DECREASE_GTE',
        'ABS_INCREASE_GTE',
    ];

    /** @var list<string> */
    private const array GRAINS = [
        'PER_DIGITAL_ASSET',
        'PER_WEBSITE_PAGE',
        'PER_CAMPAIGN',
        'PER_QUERY_BOUNDED',
        'PER_ACCOUNT',
        'PER_BRAND',
        'AGGREGATE',
    ];

    public function __construct(
        private readonly EvidenceDefinitionRegistry $evidenceDefinitions,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public function validate(array $decoded): void
    {
        $registryId = (string) ($decoded['metadata']['registry_id'] ?? '');
        if ($registryId !== (string) config('moxdop-finding-rules.finding_rule_registry_id')) {
            throw new RuntimeException("Unsupported Finding rule registry id [{$registryId}].");
        }

        $version = (int) ($decoded['metadata']['version'] ?? 0);
        $supported = config('moxdop-finding-rules.supported_finding_rule_registry_versions', [1]);
        if (! in_array($version, $supported, true)) {
            throw new RuntimeException("Unsupported Finding rule registry version [{$version}].");
        }

        $invariants = is_array($decoded['invariants'] ?? null) ? $decoded['invariants'] : [];
        foreach ([
            'NO_ARBITRARY_EXPRESSIONS',
            'NO_RUNTIME_EVAL',
            'NO_GENERIC_METRIC_PROMOTION',
            'EVIDENCE_NOT_FINDING',
            'NO_PROVIDER_TABLE_BYPASS',
        ] as $flag) {
            if (($invariants[$flag] ?? false) !== true) {
                throw new RuntimeException("Finding rule registry must enforce {$flag}.");
            }
        }

        $rules = $decoded['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            throw new RuntimeException('Finding rule registry must declare at least one rule.');
        }

        $ids = [];
        $stableIds = [];
        foreach ($rules as $index => $raw) {
            if (! is_array($raw)) {
                throw new RuntimeException("Finding rule at index {$index} must be an object.");
            }
            $this->validateRule($raw, $ids, $stableIds);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $stableIds
     */
    private function validateRule(array $raw, array &$ids, array &$stableIds): void
    {
        $id = (string) ($raw['id'] ?? '');
        $stableId = (string) ($raw['stable_id'] ?? '');
        if ($id === '' || $stableId === '') {
            throw new RuntimeException('Finding rule requires id and stable_id.');
        }
        if (isset($ids[$id])) {
            throw new RuntimeException("Duplicate Finding rule id [{$id}].");
        }
        $ids[$id] = true;
        $stableIds[$stableId] = true;

        if ((int) ($raw['version'] ?? 0) < 1) {
            throw new RuntimeException("Finding rule [{$id}] requires a positive version.");
        }

        $definitions = $raw['evidence_definition_ids'] ?? [];
        if (! is_array($definitions) || $definitions === []) {
            throw new RuntimeException("Finding rule [{$id}] must reference Evidence Definition IDs.");
        }
        foreach ($definitions as $definitionId) {
            $this->evidenceDefinitions->get((string) $definitionId);
        }

        if (isset($raw['expression']) || isset($raw['php']) || isset($raw['sql'])) {
            throw new RuntimeException("Finding rule [{$id}] must not declare executable expressions.");
        }

        $grain = (string) data_get($raw, 'subject.grain', '');
        if (! in_array($grain, self::GRAINS, true)) {
            throw new RuntimeException("Finding rule [{$id}] has invalid subject grain [{$grain}].");
        }
        if (in_array($grain, ['PER_QUERY_BOUNDED', 'PER_WEBSITE_PAGE'], true)) {
            $max = (int) data_get($raw, 'cardinality.max_per_digital_asset', 0);
            if ($max < 1) {
                throw new RuntimeException("Finding rule [{$id}] high-cardinality grain requires an explicit bound.");
            }
        }

        $activation = is_array($raw['activation']['conditions'] ?? null) ? $raw['activation']['conditions'] : [];
        if ($activation === []) {
            throw new RuntimeException("Finding rule [{$id}] requires typed activation conditions.");
        }
        foreach ($activation as $condition) {
            $this->validateCondition($id, is_array($condition) ? $condition : []);
        }

        $autoResolve = (bool) ($raw['auto_resolve'] ?? false);
        $clear = is_array($raw['clear']['conditions'] ?? null) ? $raw['clear']['conditions'] : [];
        if ($autoResolve && $clear === []) {
            throw new RuntimeException("Finding rule [{$id}] sets auto_resolve but has no clear condition.");
        }
        foreach ($clear as $condition) {
            $this->validateCondition($id, is_array($condition) ? $condition : []);
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function validateCondition(string $ruleId, array $condition): void
    {
        $type = (string) ($condition['type'] ?? '');
        if (! in_array($type, self::CONDITION_TYPES, true)) {
            throw new RuntimeException("Finding rule [{$ruleId}] has unsupported condition type [{$type}].");
        }
        if (isset($condition['expression'])) {
            throw new RuntimeException("Finding rule [{$ruleId}] condition must not include expressions.");
        }

        $numericTypes = [
            'VALUE_LT', 'VALUE_LTE', 'VALUE_GT', 'VALUE_GTE', 'VALUE_EQUALS',
            'ABS_DECREASE_GTE', 'ABS_INCREASE_GTE',
        ];
        if (in_array($type, $numericTypes, true) && ! is_numeric($condition['value'] ?? null)) {
            throw new RuntimeException("Finding rule [{$ruleId}] condition [{$type}] requires a numeric threshold.");
        }
        if (in_array($type, ['VALUE_BETWEEN', 'VALUE_OUTSIDE'], true)
            && (! is_numeric($condition['min'] ?? null) || ! is_numeric($condition['max'] ?? null))) {
            throw new RuntimeException("Finding rule [{$ruleId}] condition [{$type}] requires numeric min and max.");
        }
        if (in_array($type, ['ABS_DECREASE_GTE', 'ABS_INCREASE_GTE'], true)
            && ((string) ($condition['current_path'] ?? '') === '' || (string) ($condition['previous_path'] ?? '') === '')) {
            throw new RuntimeException("Finding rule [{$ruleId}] {$type} requires current_path and previous_path.");
        }
        if (isset($condition['unit']) && (string) $condition['unit'] === '') {
            throw new RuntimeException("Finding rule [{$ruleId}] condition unit must be non-empty when present.");
        }
    }
}
