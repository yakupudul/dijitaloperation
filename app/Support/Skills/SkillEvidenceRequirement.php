<?php

namespace App\Support\Skills;

/**
 * One Required or Optional Evidence contract entry for a Skill Definition.
 *
 * @phpstan-type RequirementArray array{
 *     key: string,
 *     kind: string,
 *     role: string,
 *     purpose: string,
 *     missing_behavior: string,
 *     freshness_policy: string|null,
 *     integrity_required: bool,
 *     completeness_required: bool,
 *     expands_conclusions: bool
 * }
 */
final class SkillEvidenceRequirement
{
    public const string KIND_EVIDENCE_DEFINITION = 'evidence_definition';

    public const string KIND_EVIDENCE_TYPE = 'evidence_type';

    public const string ROLE_PRIMARY_FACT = 'PRIMARY_FACT';

    public const string ROLE_COMPARISON_BASELINE = 'COMPARISON_BASELINE';

    public const string ROLE_SCOPE_CONTEXT = 'SCOPE_CONTEXT';

    public const string ROLE_MEASUREMENT_CONTEXT = 'MEASUREMENT_CONTEXT';

    public const string ROLE_MARKET_CONTEXT = 'MARKET_CONTEXT';

    public const string ROLE_OPTIONAL_ENRICHMENT = 'OPTIONAL_ENRICHMENT';

    public const string MISSING_ABSTAIN = 'ABSTAIN';

    public const string MISSING_CONTINUE = 'CONTINUE';

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $key = trim((string) ($raw['key'] ?? ''));
        $kind = trim((string) ($raw['kind'] ?? self::KIND_EVIDENCE_TYPE));
        $role = trim((string) ($raw['role'] ?? self::ROLE_PRIMARY_FACT));
        $purpose = trim((string) ($raw['purpose'] ?? ''));
        $missing = trim((string) ($raw['missing_behavior'] ?? self::MISSING_ABSTAIN));
        $freshness = isset($raw['freshness_policy']) ? trim((string) $raw['freshness_policy']) : null;
        $integrity = (bool) ($raw['integrity_required'] ?? true);
        $completeness = (bool) ($raw['completeness_required'] ?? false);
        $expands = (bool) ($raw['expands_conclusions'] ?? false);

        return new self(
            key: $key,
            kind: $kind !== '' ? $kind : self::KIND_EVIDENCE_TYPE,
            role: $role !== '' ? $role : self::ROLE_PRIMARY_FACT,
            purpose: $purpose,
            missingBehavior: $missing !== '' ? $missing : self::MISSING_ABSTAIN,
            freshnessPolicy: $freshness !== '' ? $freshness : null,
            integrityRequired: $integrity,
            completenessRequired: $completeness,
            expandsConclusions: $expands,
        );
    }

    public static function fromLegacyKey(string $key, bool $optional = false): self
    {
        return new self(
            key: trim($key),
            kind: self::KIND_EVIDENCE_TYPE,
            role: $optional ? self::ROLE_OPTIONAL_ENRICHMENT : self::ROLE_PRIMARY_FACT,
            purpose: $optional ? 'Optional enrichment when available.' : 'Required primary factual input.',
            missingBehavior: $optional ? self::MISSING_CONTINUE : self::MISSING_ABSTAIN,
            freshnessPolicy: null,
            integrityRequired: ! $optional,
            completenessRequired: false,
            expandsConclusions: false,
        );
    }

    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $role,
        public readonly string $purpose,
        public readonly string $missingBehavior,
        public readonly ?string $freshnessPolicy,
        public readonly bool $integrityRequired,
        public readonly bool $completenessRequired,
        public readonly bool $expandsConclusions,
    ) {}

    /**
     * @return RequirementArray
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'role' => $this->role,
            'purpose' => $this->purpose,
            'missing_behavior' => $this->missingBehavior,
            'freshness_policy' => $this->freshnessPolicy,
            'integrity_required' => $this->integrityRequired,
            'completeness_required' => $this->completenessRequired,
            'expands_conclusions' => $this->expandsConclusions,
        ];
    }
}
