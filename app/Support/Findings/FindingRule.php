<?php

namespace App\Support\Findings;

/**
 * Machine-readable Finding Rule. Trusted application config — never request input.
 *
 * @phpstan-type Condition array{
 *     type: string,
 *     path?: string,
 *     current_path?: string,
 *     previous_path?: string,
 *     value?: mixed,
 *     min?: float,
 *     max?: float,
 *     unit?: string,
 *     negate?: bool
 * }
 */
final class FindingRule
{
    /**
     * @param  list<string>  $evidenceDefinitionIds
     * @param  list<array{path: string, equals: string}>  $requiredOperandStates
     * @param  list<Condition>  $activationConditions
     * @param  list<Condition>  $clearConditions
     * @param  array{kind: string, grain: string}  $subject
     * @param  array{strategy: string, max_per_digital_asset: int, bound: string}  $cardinality
     * @param  list<string>  $fingerprintInputs
     * @param  list<string>  $evaluationFingerprintInputs
     * @param  list<string>  $joinKey
     */
    public function __construct(
        public readonly string $id,
        public readonly string $stableId,
        public readonly int $version,
        public readonly bool $enabled,
        public readonly string $meaning,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $severityPolicy,
        public readonly string $sourceModule,
        public readonly array $evidenceDefinitionIds,
        public readonly array $subject,
        public readonly array $cardinality,
        public readonly array $activationConditions,
        public readonly string $activationCombiner,
        public readonly array $clearConditions,
        public readonly string $clearCombiner,
        public readonly bool $autoResolve,
        public readonly string $reopenPolicy,
        public readonly string $integrityRequirement,
        public readonly string $freshnessRequirement,
        public readonly string $completenessRequirement,
        public readonly string $providerLimitationPolicy,
        public readonly string $activationPolicy,
        public readonly string $goalOfferingPolicy,
        public readonly bool $includeGoalInFingerprint,
        public readonly bool $includeOfferingInFingerprint,
        public readonly bool $includePeriodInFingerprint,
        public readonly array $requiredOperandStates,
        public readonly array $fingerprintInputs,
        public readonly array $evaluationFingerprintInputs,
        public readonly array $joinKey,
        public readonly string $titleTemplate,
        public readonly string $summaryTemplate,
        public readonly string $thresholdSource,
        public readonly string $currencyPolicy,
        public readonly string $consumerIntent,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $activation = is_array($raw['activation'] ?? null) ? $raw['activation'] : [];
        $clear = is_array($raw['clear'] ?? null) ? $raw['clear'] : [];
        $subject = is_array($raw['subject'] ?? null) ? $raw['subject'] : [];
        $cardinality = is_array($raw['cardinality'] ?? null) ? $raw['cardinality'] : [];

        return new self(
            id: (string) ($raw['id'] ?? ''),
            stableId: (string) ($raw['stable_id'] ?? ''),
            version: (int) ($raw['version'] ?? 0),
            enabled: (bool) ($raw['enabled'] ?? false),
            meaning: (string) ($raw['meaning'] ?? ''),
            category: (string) ($raw['category'] ?? 'performance'),
            severity: (string) ($raw['severity'] ?? 'medium'),
            severityPolicy: (string) ($raw['severity_policy'] ?? 'static'),
            sourceModule: (string) ($raw['source_module'] ?? ''),
            evidenceDefinitionIds: array_values(array_map('strval', $raw['evidence_definition_ids'] ?? [])),
            subject: [
                'kind' => (string) ($subject['kind'] ?? 'digital_asset'),
                'grain' => (string) ($subject['grain'] ?? 'PER_DIGITAL_ASSET'),
            ],
            cardinality: [
                'strategy' => (string) ($cardinality['strategy'] ?? 'PER_DIGITAL_ASSET'),
                'max_per_digital_asset' => (int) ($cardinality['max_per_digital_asset'] ?? 1),
                'bound' => (string) ($cardinality['bound'] ?? 'one_finding_per_asset'),
            ],
            activationConditions: is_array($activation['conditions'] ?? null) ? $activation['conditions'] : [],
            activationCombiner: (string) ($activation['combiner'] ?? 'ALL'),
            clearConditions: is_array($clear['conditions'] ?? null) ? $clear['conditions'] : [],
            clearCombiner: (string) ($clear['combiner'] ?? 'ALL'),
            autoResolve: (bool) ($raw['auto_resolve'] ?? false),
            reopenPolicy: (string) ($raw['reopen_policy'] ?? 'REOPEN_SAME_FINDING'),
            integrityRequirement: (string) ($raw['integrity_requirement'] ?? 'trusted'),
            freshnessRequirement: (string) ($raw['freshness_requirement'] ?? 'fresh_or_fresh_with_limitation'),
            completenessRequirement: (string) ($raw['completeness_requirement'] ?? 'required_states_are_VALUE'),
            providerLimitationPolicy: (string) ($raw['provider_limitation_policy'] ?? 'block_not_false'),
            activationPolicy: (string) ($raw['activation_policy'] ?? 'IMMEDIATE'),
            goalOfferingPolicy: (string) ($raw['goal_offering_policy'] ?? 'inherit_explicit_evidence_scope_do_not_infer'),
            includeGoalInFingerprint: (bool) ($raw['include_goal_in_fingerprint'] ?? false),
            includeOfferingInFingerprint: (bool) ($raw['include_offering_in_fingerprint'] ?? false),
            includePeriodInFingerprint: (bool) ($raw['include_period_in_fingerprint'] ?? false),
            requiredOperandStates: is_array($raw['required_operand_states'] ?? null) ? $raw['required_operand_states'] : [],
            fingerprintInputs: array_values(array_map('strval', $raw['fingerprint_inputs'] ?? [])),
            evaluationFingerprintInputs: array_values(array_map('strval', $raw['evaluation_fingerprint_inputs'] ?? [])),
            joinKey: array_values(array_map('strval', $raw['join_key'] ?? ['customer_id', 'brand_id', 'digital_asset_id'])),
            titleTemplate: (string) ($raw['title_template'] ?? ''),
            summaryTemplate: (string) ($raw['summary_template'] ?? ''),
            thresholdSource: (string) ($raw['threshold_source'] ?? ''),
            currencyPolicy: (string) ($raw['currency_policy'] ?? 'not_applicable'),
            consumerIntent: (string) ($raw['consumer_intent'] ?? 'operator_finding_queue'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function conditionConfigIdentity(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'activation' => $this->activationConditions,
            'clear' => $this->clearConditions,
        ];
    }
}
