<?php

namespace App\Services\Opportunities;

use App\Services\Evidence\EvidenceDefinitionRegistry;
use App\Services\Findings\FindingRuleRegistry;
use RuntimeException;

/**
 * Validates Opportunity rules against Evidence Definition Registry, Finding Rule Registry,
 * typed operators, and clear/auto-close invariants. No arbitrary Finding→Opportunity promotion.
 */
final class OpportunityRuleValidator
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
        'FINDING_PRESENT',
        'FINDING_ABSENT_WITH_PROOF',
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
        private readonly FindingRuleRegistry $findingRules,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public function validate(array $decoded): void
    {
        $registryId = (string) ($decoded['metadata']['registry_id'] ?? '');
        if ($registryId !== (string) config('moxdop-opportunity-rules.opportunity_rule_registry_id')) {
            throw new RuntimeException("Unsupported Opportunity rule registry id [{$registryId}].");
        }

        $version = (int) ($decoded['metadata']['version'] ?? 0);
        $supported = config('moxdop-opportunity-rules.supported_opportunity_rule_registry_versions', [1]);
        if (! in_array($version, $supported, true)) {
            throw new RuntimeException("Unsupported Opportunity rule registry version [{$version}].");
        }

        $invariants = is_array($decoded['invariants'] ?? null) ? $decoded['invariants'] : [];
        foreach ([
            'NO_ARBITRARY_EXPRESSIONS',
            'NO_RUNTIME_EVAL',
            'NO_GENERIC_METRIC_PROMOTION',
            'NO_GENERIC_FINDING_PROMOTION',
            'EVIDENCE_NOT_FINDING',
            'FINDING_NOT_OPPORTUNITY',
            'NO_PROVIDER_TABLE_BYPASS',
            'NO_MAGIC_SCORE',
            'MISSING_IS_NOT_CLEARED',
            'STALE_IS_NOT_CLEARED',
            'PARTIAL_IS_NOT_CLEARED',
            'NO_GOAL_OFFERING_NAME_INFERENCE',
            'NO_SERVICE_SCOPE_AUTO_CREATE',
        ] as $flag) {
            if (($invariants[$flag] ?? false) !== true) {
                throw new RuntimeException("Opportunity rule registry must enforce {$flag}.");
            }
        }

        $rules = $decoded['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            throw new RuntimeException('Opportunity rule registry must declare at least one rule.');
        }

        $ids = [];
        $stableIds = [];
        foreach ($rules as $index => $raw) {
            if (! is_array($raw)) {
                throw new RuntimeException("Opportunity rule at index {$index} must be an object.");
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
            throw new RuntimeException('Opportunity rule requires id and stable_id.');
        }
        if (isset($ids[$id])) {
            throw new RuntimeException("Duplicate Opportunity rule id [{$id}].");
        }
        $ids[$id] = true;
        $stableIds[$stableId] = true;

        if ((int) ($raw['version'] ?? 0) < 1) {
            throw new RuntimeException("Opportunity rule [{$id}] requires a positive version.");
        }

        $definitions = $raw['evidence_definition_ids'] ?? [];
        if (! is_array($definitions) || $definitions === []) {
            throw new RuntimeException("Opportunity rule [{$id}] must reference Evidence Definition IDs.");
        }
        foreach ($definitions as $definitionId) {
            $this->evidenceDefinitions->get((string) $definitionId);
        }

        $findingRuleStableIds = $raw['finding_rule_stable_ids'] ?? [];
        if (! is_array($findingRuleStableIds) || $findingRuleStableIds === []) {
            throw new RuntimeException("Opportunity rule [{$id}] must reference at least one Finding Rule stable ID.");
        }
        foreach ($findingRuleStableIds as $findingStableId) {
            if ($this->findingRules->byStableId((string) $findingStableId) === null) {
                throw new RuntimeException("Opportunity rule [{$id}] references unknown Finding rule stable ID [{$findingStableId}].");
            }
        }

        if (isset($raw['expression']) || isset($raw['php']) || isset($raw['sql'])) {
            throw new RuntimeException("Opportunity rule [{$id}] must not declare executable expressions.");
        }

        if (isset($raw['score']) || isset($raw['opportunity_score']) || isset($raw['weight'])) {
            throw new RuntimeException("Opportunity rule [{$id}] must not declare a magic score field.");
        }

        $grain = (string) data_get($raw, 'subject.grain', '');
        if (! in_array($grain, self::GRAINS, true)) {
            throw new RuntimeException("Opportunity rule [{$id}] has invalid subject grain [{$grain}].");
        }
        if (in_array($grain, ['PER_QUERY_BOUNDED', 'PER_WEBSITE_PAGE'], true)) {
            $max = (int) data_get($raw, 'cardinality.max_per_digital_asset', 0);
            if ($max < 1) {
                throw new RuntimeException("Opportunity rule [{$id}] high-cardinality grain requires an explicit bound.");
            }
        }

        $activation = is_array($raw['activation']['conditions'] ?? null) ? $raw['activation']['conditions'] : [];
        if ($activation === []) {
            throw new RuntimeException("Opportunity rule [{$id}] requires typed activation conditions.");
        }
        foreach ($activation as $condition) {
            $this->validateCondition($id, is_array($condition) ? $condition : [], $findingRuleStableIds);
        }

        $autoClose = (bool) ($raw['auto_close'] ?? false);
        $clear = is_array($raw['clear']['conditions'] ?? null) ? $raw['clear']['conditions'] : [];
        if ($autoClose && $clear === []) {
            throw new RuntimeException("Opportunity rule [{$id}] sets auto_close but has no clear condition.");
        }
        foreach ($clear as $condition) {
            $this->validateCondition($id, is_array($condition) ? $condition : [], $findingRuleStableIds);
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<mixed>  $findingRuleStableIds
     */
    private function validateCondition(string $ruleId, array $condition, array $findingRuleStableIds): void
    {
        $type = (string) ($condition['type'] ?? '');
        if (! in_array($type, self::CONDITION_TYPES, true)) {
            throw new RuntimeException("Opportunity rule [{$ruleId}] has unsupported condition type [{$type}].");
        }
        if (isset($condition['expression'])) {
            throw new RuntimeException("Opportunity rule [{$ruleId}] condition must not include expressions.");
        }

        if (in_array($type, ['FINDING_PRESENT', 'FINDING_ABSENT_WITH_PROOF'], true)) {
            $findingStableId = (string) ($condition['finding_rule_stable_id'] ?? '');
            if ($findingStableId === '') {
                throw new RuntimeException("Opportunity rule [{$ruleId}] {$type} requires finding_rule_stable_id.");
            }
            if (! in_array($findingStableId, array_map('strval', $findingRuleStableIds), true)) {
                throw new RuntimeException("Opportunity rule [{$ruleId}] {$type} references a Finding rule not declared in finding_rule_stable_ids.");
            }

            return;
        }

        $numericTypes = [
            'VALUE_LT', 'VALUE_LTE', 'VALUE_GT', 'VALUE_GTE', 'VALUE_EQUALS',
            'ABS_DECREASE_GTE', 'ABS_INCREASE_GTE',
        ];
        if (in_array($type, $numericTypes, true) && ! is_numeric($condition['value'] ?? null)) {
            throw new RuntimeException("Opportunity rule [{$ruleId}] condition [{$type}] requires a numeric threshold.");
        }
        if (in_array($type, ['VALUE_BETWEEN', 'VALUE_OUTSIDE'], true)
            && (! is_numeric($condition['min'] ?? null) || ! is_numeric($condition['max'] ?? null))) {
            throw new RuntimeException("Opportunity rule [{$ruleId}] condition [{$type}] requires numeric min and max.");
        }
        if (in_array($type, ['ABS_DECREASE_GTE', 'ABS_INCREASE_GTE'], true)
            && ((string) ($condition['current_path'] ?? '') === '' || (string) ($condition['previous_path'] ?? '') === '')) {
            throw new RuntimeException("Opportunity rule [{$ruleId}] {$type} requires current_path and previous_path.");
        }
        if (isset($condition['unit']) && (string) $condition['unit'] === '') {
            throw new RuntimeException("Opportunity rule [{$ruleId}] condition unit must be non-empty when present.");
        }
    }
}
